<?php

namespace App\Actions\Pos;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Orders\ApplyOrderMilestones;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\SetOrderStatus;
use App\Actions\Orders\ShipOrderStock;
use App\Enums\OrderStatus;
use App\Enums\ShiftStatus;
use App\Enums\TenderType;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The POS sale (CONTEXT.md Payment / Manual Discount / Receipt): one
 * transaction takes the cart, prices List Price − Manual Discount,
 * collects split-tender Payment Lines (total ≥ order total; only cash
 * gives change), fires the instant lifecycle (รอชำระ → สำเร็จ + one SHIP,
 * Reserved untouched), and assigns the Shop's next receipt_no.
 *
 * Rounding site (ADR 0015): a % discount rounds HALF-UP to whole satang
 * at the level it applies (line % → per line; a cart discount is taken
 * as a baht amount on the cart total).
 */
class CheckoutPosSale
{
    public function __construct(
        private readonly CreateOrder $createOrder,
        private readonly SetOrderStatus $setStatus,
        private readonly ApplyOrderMilestones $milestones,
        private readonly ShipOrderStock $ship,
        private readonly RecordAuditLog $audit,
        private readonly NextReceiptNumber $receiptNumber,
    ) {}

    /**
     * @param  list<array{variant: Variant, qty: int, discount_baht?: Money, discount_percent?: float}>  $items
     * @param  list<array{tender: TenderType, amount: Money}>  $tenders
     */
    public function handle(Shift $shift, array $items, array $tenders, ?Money $cartDiscount = null): Order
    {
        if ($shift->status !== ShiftStatus::Open) {
            throw new LogicException('A POS sale needs an open Shift on its Register.');
        }

        $user = auth()->user();

        if (! $user instanceof User || ! $user->checkPermissionTo('pos.checkout')) {
            throw new AuthorizationException('Checkout requires the pos.checkout permission.');
        }

        [$lines, $hasDiscount] = $this->resolveLines($items);
        $hasDiscount = $hasDiscount || ($cartDiscount !== null && ! $cartDiscount->isZero());

        if ($hasDiscount && ! $user->checkPermissionTo('sale.discount')) {
            throw new AuthorizationException('A Manual Discount requires the sale.discount permission.');
        }

        return DB::transaction(function () use ($shift, $lines, $tenders, $cartDiscount, $hasDiscount): Order {
            $shop = $shift->register()->firstOrFail()->shop()->firstOrFail();

            $order = $this->createOrder->handle($shop, $lines, cartDiscount: $cartDiscount);
            $order->update(['shift_id' => $shift->id]);

            $this->takePayment($order, $tenders);

            if ($hasDiscount) {
                $this->audit->handle('sale.discount', $order, [
                    'order_total' => $order->refresh()->total?->satang,
                ]);
            }

            // The instant POS lifecycle: one SHIP, Reserved untouched.
            $this->ship->handle($order);
            $this->setStatus->handle($order, OrderStatus::Completed);
            $this->milestones->handle($order, ['paid_date' => now(), 'completed_date' => now()]);

            $order->update(['receipt_no' => $this->receiptNumber->handle($shop)]);

            return $order->refresh()->load(['lines', 'payments']);
        });
    }

    /**
     * @param  list<array{variant: Variant, qty: int, discount_baht?: Money, discount_percent?: float}>  $items
     * @return array{list<array{variant: Variant, qty: int, unit_price: Money, discount: Money}>, bool}
     */
    private function resolveLines(array $items): array
    {
        $lines = [];
        $hasDiscount = false;

        foreach ($items as $item) {
            $unitPrice = $item['variant']->list_price
                ?? throw new InvalidArgumentException("Variant [{$item['variant']->master_sku}] has no List Price.");

            $gross = $unitPrice->multiply($item['qty']);
            $discount = $item['discount_baht'] ?? Money::fromSatang(0);

            if (isset($item['discount_percent'])) {
                $discount = $discount->add($this->percentOf($gross, $item['discount_percent']));
            }

            if ($discount->isNegative() || $gross->subtract($discount)->isNegative()) {
                throw new InvalidArgumentException('A Manual Discount must stay between zero and the line amount.');
            }

            $hasDiscount = $hasDiscount || ! $discount->isZero();

            $lines[] = [
                'variant' => $item['variant'],
                'qty' => $item['qty'],
                'unit_price' => $unitPrice,
                'discount' => $discount,
            ];
        }

        return [$lines, $hasDiscount];
    }

    /**
     * % of an amount, HALF-UP to whole satang, in pure integer math
     * (basis points) — no float ever touches the money (ADR 0015).
     */
    private function percentOf(Money $amount, float $percent): Money
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('A % discount must be between 0 and 100.');
        }

        $basisPoints = (int) round($percent * 100);

        return Money::fromSatang(intdiv($amount->satang * $basisPoints + 5_000, 10_000));
    }

    /**
     * @param  list<array{tender: TenderType, amount: Money}>  $tenders
     */
    private function takePayment(Order $order, array $tenders): void
    {
        if ($tenders === []) {
            throw new InvalidArgumentException('A POS sale needs at least one Payment Line.');
        }

        $total = $order->refresh()->total ?? Money::fromSatang(0);
        $tendered = Money::fromSatang(0);
        $cash = Money::fromSatang(0);

        foreach ($tenders as $tender) {
            if ($tender['amount']->isNegative() || $tender['amount']->isZero()) {
                throw new InvalidArgumentException('A Payment Line amount must be positive.');
            }

            $tendered = $tendered->add($tender['amount']);

            if ($tender['tender'] === TenderType::Cash) {
                $cash = $cash->add($tender['amount']);
            }
        }

        if ($tendered->subtract($total)->isNegative()) {
            throw new InvalidArgumentException('The tendered total is below the order total.');
        }

        $change = $tendered->subtract($total);

        if (! $change->isZero() && $change->subtract($cash)->satang > 0) {
            throw new InvalidArgumentException('Change exceeds the cash received — only cash gives change.');
        }

        foreach ($tenders as $tender) {
            $order->payments()->create([
                'tender_type' => $tender['tender'],
                'amount' => $tender['amount'],
            ]);
        }
    }
}
