<?php

namespace App\Actions\Returns;

use App\Enums\RefundStatus;
use App\Enums\ReturnSubStatus;
use App\Models\Order;

/**
 * The Order-level Refund Status rollup (CONTEXT.md: Refund Status;
 * ADR 0006): derived on read from the Order's Returns — a Platform-closed
 * case (ยกเลิกการคืน) refunded nothing and never counts. Precedence: an
 * unrefunded case in flight reads รอคืน; otherwise the refunded
 * quantities decide เต็มจำนวน vs บางส่วน per Order Line qty.
 */
class DeriveRefundStatus
{
    public function handle(Order $order): RefundStatus
    {
        $returns = $order->returns()
            ->where('sub_status', '!=', ReturnSubStatus::Closed)
            ->with('lines')
            ->get();

        if ($returns->isEmpty()) {
            return RefundStatus::None;
        }

        if ($returns->contains(fn ($return): bool => $return->refunded_at === null)) {
            return RefundStatus::Pending;
        }

        $refundedQty = [];

        foreach ($returns as $return) {
            foreach ($return->lines as $line) {
                $refundedQty[$line->ref_order_line_id] = ($refundedQty[$line->ref_order_line_id] ?? 0) + $line->qty;
            }
        }

        foreach ($order->lines()->get() as $orderLine) {
            if (($refundedQty[$orderLine->id] ?? 0) < $orderLine->qty) {
                return RefundStatus::Partial;
            }
        }

        return RefundStatus::Full;
    }
}
