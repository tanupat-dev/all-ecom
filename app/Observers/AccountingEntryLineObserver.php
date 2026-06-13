<?php

namespace App\Observers;

use App\Actions\Accounting\RecomputeDailyPnl;
use App\Models\AccountingEntryLine;
use App\Observers\Concerns\MarksDailyPnlDirty;

/**
 * Marks the Daily P&L bucket dirty whenever a marketplace Order's accounting
 * line is written or removed (Issue #71) — this catches the cycle-replace
 * re-import path (ADR 0007) without touching UpsertAccountingCycle. The bucket
 * is the line's Order's sale date (created_date → Bangkok day).
 */
class AccountingEntryLineObserver
{
    use MarksDailyPnlDirty;

    public function saved(AccountingEntryLine $line): void
    {
        $this->mark($line);
    }

    public function deleted(AccountingEntryLine $line): void
    {
        $this->mark($line);
    }

    private function mark(AccountingEntryLine $line): void
    {
        $order = $line->order;

        $this->dispatchRecompute(
            $line->tenant_id,
            $order?->shop_id,
            $order?->created_date !== null ? RecomputeDailyPnl::bucketDate($order->created_date) : null,
        );
    }
}
