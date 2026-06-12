<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PlatformType;
use App\Enums\StockAction;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Pre-pack line edit for social/pos Orders (CONTEXT.md: Reserved Stock /
 * Order): replaces the lines and — when the Order has reserved stock —
 * appends compensating movements for the deltas: +qty → extra RESERVE,
 * −qty/removed → partial RELEASE, swapped Variant → RELEASE old +
 * RESERVE new. Post-pack (Tracking exists) the lines are locked.
 */
class EditOrderLines
{
    public function __construct(
        private readonly MoveOrderStock $move,
    ) {}

    /**
     * @param  non-empty-list<array{variant: Variant, qty: int, unit_price: Money, discount?: Money}>  $lines
     */
    public function handle(Order $order, array $lines): Order
    {
        if ($order->platform_type === PlatformType::Marketplace) {
            throw new LogicException('Marketplace Orders are read-only mirrors — edits flow in via re-import only.');
        }

        if (! $order->isPrePack()) {
            throw new LogicException('Post-pack lines are locked (Tracking Number exists).');
        }

        if ($lines === []) {
            throw new InvalidArgumentException('An Order needs at least one Order Line.');
        }

        return DB::transaction(function () use ($order, $lines): Order {
            $oldQtyByVariant = [];

            foreach ($order->lines()->get() as $existing) {
                $oldQtyByVariant[$existing->variant_id] = ($oldQtyByVariant[$existing->variant_id] ?? 0) + $existing->qty;
            }

            $newQtyByVariant = [];

            foreach ($lines as $line) {
                $id = $line['variant']->id;
                $newQtyByVariant[$id] = ($newQtyByVariant[$id] ?? 0) + $line['qty'];
            }

            // Only an Order that actually reserved stock compensates.
            if ($order->status === OrderStatus::AwaitingPack) {
                $this->compensate($order, $oldQtyByVariant, $newQtyByVariant, $lines);
            }

            $order->lines()->delete();

            $total = Money::fromSatang(0);

            foreach ($lines as $line) {
                $discount = $line['discount'] ?? Money::fromSatang(0);
                $lineTotal = $line['unit_price']->multiply($line['qty'])->subtract($discount);
                $total = $total->add($lineTotal);

                $order->lines()->create([
                    'variant_id' => $line['variant']->id,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount' => $discount,
                    'line_total' => $lineTotal,
                ]);
            }

            $order->update(['total' => $total->subtract($order->cart_discount ?? Money::fromSatang(0))]);

            return $order->load('lines');
        });
    }

    /**
     * @param  array<int, int>  $old  variant_id => qty
     * @param  array<int, int>  $new  variant_id => qty
     * @param  list<array{variant: Variant, qty: int, unit_price: Money}>  $lines
     */
    private function compensate(Order $order, array $old, array $new, array $lines): void
    {
        $variantsById = [];

        foreach ($lines as $line) {
            $variantsById[$line['variant']->id] = $line['variant'];
        }

        foreach ($new + $old as $variantId => $unused) {
            $delta = ($new[$variantId] ?? 0) - ($old[$variantId] ?? 0);

            if ($delta === 0) {
                continue;
            }

            $variant = $variantsById[$variantId] ?? Variant::query()->findOrFail($variantId);

            $this->move->handle(
                $order,
                $delta > 0 ? StockAction::Reserve : StockAction::Release,
                [new OrderLine(['variant_id' => $variant->id, 'qty' => abs($delta)])],
            );
        }
    }
}
