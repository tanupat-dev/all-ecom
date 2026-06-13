<?php

namespace App\Observers;

use App\Actions\Accounting\RecomputeDailyPnl;
use App\Models\Payment;
use App\Observers\Concerns\MarksDailyPnlDirty;

/**
 * Marks the Daily P&L bucket dirty whenever a POS Order's Payment line is
 * written or removed (Issue #71) — POS revenue feeds the rollup directly. The
 * bucket is the payment's Order's sale date (created_date → Bangkok day).
 */
class PaymentObserver
{
    use MarksDailyPnlDirty;

    public function saved(Payment $payment): void
    {
        $this->mark($payment);
    }

    public function deleted(Payment $payment): void
    {
        $this->mark($payment);
    }

    private function mark(Payment $payment): void
    {
        $order = $payment->order;

        $this->dispatchRecompute(
            $payment->tenant_id,
            $order?->shop_id,
            $order?->created_date !== null ? RecomputeDailyPnl::bucketDate($order->created_date) : null,
        );
    }
}
