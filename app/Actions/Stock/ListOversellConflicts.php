<?php

namespace App\Actions\Stock;

use App\Enums\OrderStatus;
use App\Enums\StockAction;
use App\Models\Order;
use App\Models\StockBalance;
use App\Models\StockMovement;

/**
 * The Oversell alert (CONTEXT.md: Oversell): every (Variant, Location)
 * whose Available went negative, with the pre-pack Orders holding its
 * reservations and the latest of them suggested as cancel candidates —
 * first-come-first-served honours the earlier Orders. Never cancels
 * anything itself; resolution is import-driven (re-import shows ยกเลิก →
 * RELEASE). Balances come from the denormalized columns; the per-Order
 * drilldown reads the order-ref'd RESERVE/RELEASE rows (a Bundle order
 * surfaces here through its components, ADR 0014).
 */
class ListOversellConflicts
{
    /**
     * @return list<array{balance: StockBalance, conflicts: list<array{order: Order, held: int, suggested: bool}>}>
     */
    public function handle(): array
    {
        $alerts = [];

        $negatives = StockBalance::query()
            ->whereRaw('on_hand - reserved - buffer < 0')
            ->with(['variant', 'location'])
            ->get();

        foreach ($negatives as $balance) {
            $alerts[] = [
                'balance' => $balance,
                'conflicts' => $this->conflicts($balance),
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array{order: Order, held: int, suggested: bool}>
     */
    private function conflicts(StockBalance $balance): array
    {
        $heldByOrder = StockMovement::query()
            ->where('variant_id', $balance->variant_id)
            ->where('location_id', $balance->location_id)
            ->whereIn('action', [StockAction::Reserve, StockAction::Release])
            ->where('ref_type', (new Order)->getMorphClass())
            ->groupBy('ref_id')
            ->selectRaw('ref_id, sum(qty_delta) as held')
            ->havingRaw('sum(qty_delta) > 0')
            ->pluck('held', 'ref_id');

        $orders = Order::query()
            ->whereIn('id', $heldByOrder->keys())
            ->where('status', OrderStatus::AwaitingPack)
            ->orderByDesc('created_date')
            ->orderByDesc('id')
            ->get();

        $deficit = -$balance->available;
        $covered = 0;
        $conflicts = [];

        foreach ($orders as $order) {
            $held = $heldByOrder[$order->id];
            $held = is_numeric($held) ? (int) $held : 0;
            $suggested = $covered < $deficit;

            $conflicts[] = ['order' => $order, 'held' => $held, 'suggested' => $suggested];

            if ($suggested) {
                $covered += $held;
            }
        }

        return $conflicts;
    }
}
