<?php

namespace App\Observers;

use App\Actions\Accounting\RecomputeDailyPnl;
use App\Models\Shift;
use App\Observers\Concerns\MarksDailyPnlDirty;

/**
 * Marks the Daily P&L bucket dirty whenever a Shift is written or removed
 * (Issue #71) — its Cash Over/Short posts to the P&L. The bucket is the
 * Shift's close date (closed_at → Bangkok day); an open Shift has no close
 * date (and no over_short yet) so it resolves to no bucket and is skipped. The
 * shop link is Shift → Register → Shop.
 */
class ShiftObserver
{
    use MarksDailyPnlDirty;

    public function saved(Shift $shift): void
    {
        $this->mark($shift);
    }

    public function deleted(Shift $shift): void
    {
        $this->mark($shift);
    }

    private function mark(Shift $shift): void
    {
        $this->dispatchRecompute(
            $shift->tenant_id,
            $shift->register?->shop_id,
            $shift->closed_at !== null ? RecomputeDailyPnl::bucketDate($shift->closed_at) : null,
        );
    }
}
