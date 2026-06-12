<?php

namespace App\Actions\Orders;

use App\Enums\PlatformType;
use App\Imports\NormalizedOrder;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Variant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The channel-agnostic import core (ROADMAP Phase 4): upserts one
 * platform order as a read-only mirror Order, deduped on
 * (tenant, shop, platform_order_id) — re-importing a snapshot is
 * idempotent (CONTEXT.md: Order). Milestones merge without null-overwrite
 * (ADR 0004); each snapshot's stock effect reconciles through
 * ReconcileImportedOrderStock (Reserved reconcile, ROADMAP Phase 4).
 */
class ImportMarketplaceOrder
{
    public function __construct(
        private readonly ApplyOrderMilestones $applyMilestones,
        private readonly SetOrderStatus $setStatus,
        private readonly ReconcileImportedOrderStock $reconcileStock,
    ) {}

    /**
     * $mergeLines: a platform importer whose order's rows were split
     * across two pipeline chunks passes true for the later part — the
     * lines append to the first part instead of replacing it. A fresh
     * occurrence (new run / new file) always replaces.
     */
    public function handle(Shop $shop, NormalizedOrder $normalized, bool $mergeLines = false): Order
    {
        if ($shop->platform_type !== PlatformType::Marketplace) {
            throw new LogicException('The import core mirrors marketplace Orders only — pos/social Orders are created directly.');
        }

        if ($normalized->lines === []) {
            throw new InvalidArgumentException('An Order needs at least one Order Line.');
        }

        foreach ($normalized->lines as $line) {
            if ($line['qty'] < 1) {
                throw new InvalidArgumentException('An Order Line qty must be at least 1.');
            }
        }

        return DB::transaction(function () use ($shop, $normalized, $mergeLines): Order {
            $order = Order::query()
                ->where('shop_id', $shop->id)
                ->where('platform_order_id', $normalized->platformOrderId)
                ->lockForUpdate()
                ->first();

            $previousStatus = $order?->status;
            $previousQty = $order !== null ? $this->qtyByVariant($order) : [];

            if ($order === null) {
                $order = Order::query()->create([
                    'shop_id' => $shop->id,
                    'platform_type' => PlatformType::Marketplace,
                    'platform_order_id' => $normalized->platformOrderId,
                    'status' => $normalized->status,
                    'total' => Money::fromSatang(0),
                    'tracking_number' => $normalized->trackingNumber,
                    'buyer_name' => $normalized->buyerName,
                    'buyer_phone' => $normalized->buyerPhone,
                ]);
            } else {
                $this->setStatus->handle($order, $normalized->status);
                // Like milestones, these only ever fill in — a snapshot
                // omitting a column must not erase what a prior one set.
                $order->fill(array_filter([
                    'tracking_number' => $normalized->trackingNumber,
                    'buyer_name' => $normalized->buyerName,
                    'buyer_phone' => $normalized->buyerPhone,
                ], static fn (?string $v): bool => $v !== null));
                $order->save();

                if (! $mergeLines) {
                    $order->lines()->delete();
                }
            }

            foreach ($this->aggregate($normalized->lines) as $line) {
                $order->lines()->create([
                    'variant_id' => $line['variant']->id,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                ]);
            }

            $total = Money::fromSatang((int) $order->lines()->sum('line_total'));
            $order->update(['total' => $total]);

            $this->applyMilestones->handle($order, $normalized->milestones);

            $targetQty = $this->qtyByVariant($order);

            if ($mergeLines && $previousStatus !== null) {
                $appended = [];

                foreach ($targetQty as $variantId => $qty) {
                    if ($qty - ($previousQty[$variantId] ?? 0) > 0) {
                        $appended[$variantId] = $qty - ($previousQty[$variantId] ?? 0);
                    }
                }

                $this->reconcileStock->handleAppended($order, $appended);
            } else {
                $this->reconcileStock->handle($order, $previousStatus, $previousQty, $targetQty);
            }

            return $order->load('lines');
        });
    }

    /**
     * @return array<int, int> variant_id => qty across the stored lines
     */
    private function qtyByVariant(Order $order): array
    {
        $qty = [];

        foreach ($order->lines()->get() as $line) {
            $qty[$line->variant_id] = ($qty[$line->variant_id] ?? 0) + $line->qty;
        }

        return $qty;
    }

    /**
     * One platform order's rows aggregate per (Variant, unit price) — the
     * same SKU split across file rows becomes one Order Line; distinct
     * prices stay distinct and the exact line totals sum, so money stays
     * exact (ADR 0015).
     *
     * @param  list<array{variant: Variant, qty: int, unit_price: Money, line_total?: Money}>  $lines
     * @return list<array{variant: Variant, qty: int, unit_price: Money, line_total: Money}>
     */
    private function aggregate(array $lines): array
    {
        $byKey = [];

        foreach ($lines as $line) {
            $key = $line['variant']->id.'@'.$line['unit_price']->satang;
            $lineTotal = $line['line_total'] ?? $line['unit_price']->multiply($line['qty']);

            if (isset($byKey[$key])) {
                $byKey[$key]['qty'] += $line['qty'];
                $byKey[$key]['line_total'] = $byKey[$key]['line_total']->add($lineTotal);
            } else {
                $byKey[$key] = [...$line, 'line_total' => $lineTotal];
            }
        }

        return array_values($byKey);
    }
}
