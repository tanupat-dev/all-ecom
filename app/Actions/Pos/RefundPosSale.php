<?php

namespace App\Actions\Pos;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Stock\AppendStockMovement;
use App\Enums\OrderStatus;
use App\Enums\PlatformType;
use App\Enums\ShiftStatus;
use App\Enums\StockAction;
use App\Enums\TenderType;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shift;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * POS Return (ADR 0009): a linked negative-line Order, sign-inverted from
 * the sale — negative lines fire RECEIVE (damaged goods route on to the
 * Damaged pool), negative Payment Lines refund the buyer (cash refunds
 * feed the open Shift's expected_cash), and the approval is audited.
 *
 * Money rule, explicit (ADR 0015): a partial return of a discounted line
 * prorates the line discount per returned unit and rounds the discount
 * share DOWN — the refund can never exceed what was received.
 */
class RefundPosSale
{
    public function __construct(
        private readonly AppendStockMovement $append,
        private readonly RecordAuditLog $audit,
        private readonly NextReceiptNumber $receiptNumber,
    ) {}

    /**
     * @param  list<array{line: OrderLine, qty: int, damaged?: bool}>  $returns
     * @param  list<array{tender: TenderType, amount: Money}>  $refunds  positive amounts; stored negative
     */
    public function handle(Order $original, array $returns, array $refunds): Order
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->checkPermissionTo('sale.refund')) {
            throw new AuthorizationException('A POS refund requires the sale.refund permission (Admin approval).');
        }

        if ($original->platform_type !== PlatformType::Pos || $original->receipt_no === null || $original->status !== OrderStatus::Completed) {
            throw new LogicException('A POS Return must reference a completed POS sale with a receipt (no-receipt returns are out of MVP scope).');
        }

        if ($returns === []) {
            throw new InvalidArgumentException('A POS Return needs at least one returned line.');
        }

        $shift = Shift::query()
            ->where('status', ShiftStatus::Open)
            ->whereHas('register', fn ($query) => $query->where('shop_id', $original->shop_id))
            ->first() ?? throw new LogicException('A POS refund needs an open Shift on the Shop.');

        return DB::transaction(function () use ($original, $returns, $refunds, $shift): Order {
            $location = Location::query()->findOrFail($original->shop()->firstOrFail()->location_id);

            $refundOrder = Order::query()->create([
                'shop_id' => $original->shop_id,
                'platform_type' => PlatformType::Pos,
                'status' => OrderStatus::Completed,
                'total' => Money::fromSatang(0),
                'ref_order_id' => $original->id,
                'shift_id' => $shift->id,
                'created_date' => now(),
                'completed_date' => now(),
            ]);

            $total = Money::fromSatang(0);

            foreach ($returns as $return) {
                $value = $this->returnLine($refundOrder, $original, $return, $location);
                $total = $total->add($value);
            }

            $this->refundPayment($refundOrder, $refunds, $total);

            $refundOrder->update([
                'total' => $total->negate(),
                'receipt_no' => $this->receiptNumber->handle($original->shop()->firstOrFail()),
            ]);

            $this->audit->handle('sale.refund', $refundOrder, [
                'original_order_id' => $original->id,
                'refund_satang' => $total->satang,
            ]);

            return $refundOrder->refresh()->load(['lines', 'payments']);
        });
    }

    /**
     * Books one negative line + its stock return; returns the (positive)
     * refund value of the line.
     *
     * @param  array{line: OrderLine, qty: int, damaged?: bool}  $return
     */
    private function returnLine(Order $refundOrder, Order $original, array $return, Location $location): Money
    {
        $line = $return['line'];
        $qty = $return['qty'];

        if ($line->order_id !== $original->id) {
            throw new InvalidArgumentException('The returned line does not belong to the referenced sale.');
        }

        if ($qty < 1) {
            throw new InvalidArgumentException('A returned qty must be at least 1.');
        }

        $alreadyReturned = abs((int) OrderLine::query()
            ->where('variant_id', $line->variant_id)
            ->whereIn('order_id', Order::query()->where('ref_order_id', $original->id)->select('id'))
            ->sum('qty'));

        if ($qty > $line->qty - $alreadyReturned) {
            throw new InvalidArgumentException("Returned qty [{$qty}] exceeds what remains unreturned [{$line->qty} sold − {$alreadyReturned} returned].");
        }

        $variant = $line->variant()->firstOrFail();

        if ($variant->isBundle() && ($return['damaged'] ?? false)) {
            throw new InvalidArgumentException('A damaged Bundle return is out of MVP scope — adjust the damaged components via Stock Adjustment.');
        }

        // Discount share of the returned units, rounded DOWN (seller-safe).
        $discount = $line->discount ?? Money::fromSatang(0);
        $discountShare = Money::fromSatang(intdiv($discount->satang * $qty, $line->qty));
        $unitPrice = $line->unit_price ?? Money::fromSatang(0);
        $value = $unitPrice->multiply($qty)->subtract($discountShare);

        $refundOrder->lines()->create([
            'variant_id' => $line->variant_id,
            'qty' => -$qty,
            'unit_price' => $unitPrice,
            'discount' => $discountShare->negate(),
            'line_total' => $value->negate(),
        ]);

        $this->receiveStock($refundOrder, $variant, $location, $qty, (bool) ($return['damaged'] ?? false));

        return $value;
    }

    private function receiveStock(Order $refundOrder, Variant $variant, Location $location, int $qty, bool $damaged): void
    {
        if ($variant->isBundle()) {
            // Components come back, never "bundle stock" (ADR 0014).
            foreach ($variant->bundleComponents()->with('component')->get() as $bom) {
                $this->append->handle($bom->component()->firstOrFail(), $location, StockAction::Receive, $qty * $bom->qty, ref: $refundOrder);
            }

            return;
        }

        $this->append->handle($variant, $location, StockAction::Receive, $qty, ref: $refundOrder);

        if ($damaged) {
            // RECEIVE then DAMAGE: net effect +Damaged, On-Hand unchanged —
            // the ledger keeps both physical facts.
            $this->append->handle($variant, $location, StockAction::Damage, $qty, ref: $refundOrder);
        }
    }

    /**
     * @param  list<array{tender: TenderType, amount: Money}>  $refunds
     */
    private function refundPayment(Order $refundOrder, array $refunds, Money $returnValue): void
    {
        if ($refunds === []) {
            throw new InvalidArgumentException('A POS refund needs at least one refund Payment Line.');
        }

        $refunded = Money::fromSatang(0);

        foreach ($refunds as $refund) {
            if ($refund['amount']->isNegative() || $refund['amount']->isZero()) {
                throw new InvalidArgumentException('A refund amount must be positive — it is stored negative.');
            }

            $refunded = $refunded->add($refund['amount']);
        }

        if (! $refunded->equals($returnValue)) {
            throw new InvalidArgumentException("The refund tendered [{$refunded->toBaht()}] must equal the returned value [{$returnValue->toBaht()}].");
        }

        foreach ($refunds as $refund) {
            $refundOrder->payments()->create([
                'tender_type' => $refund['tender'],
                'amount' => $refund['amount']->negate(),
            ]);
        }
    }
}
