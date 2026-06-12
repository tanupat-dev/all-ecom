<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\StockAction;
use App\Models\Order;
use App\Models\OrderLine;

/**
 * Reserved reconcile for imported marketplace mirrors (ROADMAP Phase 4):
 * derives each snapshot's stock effect from the status phase change plus
 * the Order Line diff, appending compensating movements only (ADR 0003).
 * The phase boundaries follow CONTEXT.md — RESERVE while รอแพ็ค, SHIP at
 * the Pre→Post-Pack boundary (committed to shipping; ตีกลับ stock comes
 * back only via Inbound Scan, Phase 5), RELEASE on a pre-pack ยกเลิก.
 */
class ReconcileImportedOrderStock
{
    private const NONE = 0;

    private const RESERVED = 1;

    private const SHIPPED = 2;

    public function __construct(
        private readonly MoveOrderStock $move,
    ) {}

    /**
     * Both maps are variant_id => qty; $previousQty is what the order's
     * stored lines held BEFORE this import wrote the new ones.
     *
     * @param  array<int, int>  $previousQty
     * @param  array<int, int>  $targetQty
     */
    public function handle(Order $order, ?OrderStatus $previousStatus, array $previousQty, array $targetQty): void
    {
        $from = $this->phase($previousStatus);
        $to = $this->phase($order->status);

        // What this order holds in Reserved right now — only a รอแพ็ค
        // order holds anything (a shipped one already released on SHIP).
        $held = $from === self::RESERVED ? $previousQty : [];

        if ($to === self::RESERVED) {
            $this->alignReservation($order, $held, $targetQty);

            return;
        }

        if ($to === self::SHIPPED && $from !== self::SHIPPED) {
            // Order-aware SHIP: release exactly what it reserved — the
            // full (aligned) lines when it came through รอแพ็ค, nothing
            // when first seen post-pack (CONTEXT.md: Stock Movement).
            if ($from === self::RESERVED) {
                $this->alignReservation($order, $held, $targetQty);
            }

            $this->move->handle(
                $order,
                StockAction::Ship,
                $this->transientLines($targetQty),
                reservedReleasedPerUnit: $from === self::RESERVED ? 1 : 0,
            );

            return;
        }

        // To NONE (ยกเลิก / back to รอชำระ): release whatever it held.
        // A shipped order cancelling/bouncing moves nothing — its goods
        // return only via Inbound Scan (Phase 5).
        $this->alignReservation($order, $held, []);
    }

    /**
     * The merge path for an order split across pipeline chunks: the
     * appended lines move per the current phase; their reservation (if
     * any) was already released when the first part aligned.
     *
     * @param  array<int, int>  $appendedQty
     */
    public function handleAppended(Order $order, array $appendedQty): void
    {
        match ($this->phase($order->status)) {
            self::RESERVED => $this->move->handle($order, StockAction::Reserve, $this->transientLines($appendedQty)),
            self::SHIPPED => $this->move->handle($order, StockAction::Ship, $this->transientLines($appendedQty), reservedReleasedPerUnit: 0),
            default => null,
        };
    }

    /**
     * @param  array<int, int>  $current
     * @param  array<int, int>  $target
     */
    private function alignReservation(Order $order, array $current, array $target): void
    {
        foreach ($target + $current as $variantId => $unused) {
            $delta = ($target[$variantId] ?? 0) - ($current[$variantId] ?? 0);

            if ($delta === 0) {
                continue;
            }

            $this->move->handle(
                $order,
                $delta > 0 ? StockAction::Reserve : StockAction::Release,
                $this->transientLines([$variantId => abs($delta)]),
            );
        }
    }

    /**
     * @param  array<int, int>  $qtyByVariant
     * @return list<OrderLine>
     */
    private function transientLines(array $qtyByVariant): array
    {
        $lines = [];

        foreach ($qtyByVariant as $variantId => $qty) {
            $lines[] = new OrderLine(['variant_id' => $variantId, 'qty' => $qty]);
        }

        return $lines;
    }

    private function phase(?OrderStatus $status): int
    {
        return match ($status) {
            null, OrderStatus::PendingPayment, OrderStatus::Cancelled => self::NONE,
            OrderStatus::AwaitingPack => self::RESERVED,
            OrderStatus::Packed, OrderStatus::InTransit, OrderStatus::Delivered,
            OrderStatus::Completed, OrderStatus::Bounced => self::SHIPPED,
        };
    }
}
