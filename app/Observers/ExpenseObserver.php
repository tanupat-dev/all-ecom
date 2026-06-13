<?php

namespace App\Observers;

use App\Models\Expense;
use App\Observers\Concerns\MarksDailyPnlDirty;

/**
 * Marks the Daily P&L bucket dirty whenever an Expense is written or removed
 * (Issue #71). An Expense carries no shop_id — its only shop link is
 * ref_order_id — so only per-order-attributable expenses dirty a per-shop
 * bucket; a non-attributable expense (no ref_order) resolves to no shop and is
 * skipped (it belongs to the tenant-level monthly P&L, not this rollup). The
 * bucket date is the Expense's own `date` (a Bangkok calendar date).
 */
class ExpenseObserver
{
    use MarksDailyPnlDirty;

    public function saved(Expense $expense): void
    {
        $this->mark($expense);
    }

    public function deleted(Expense $expense): void
    {
        $this->mark($expense);
    }

    private function mark(Expense $expense): void
    {
        $this->dispatchRecompute(
            $expense->tenant_id,
            $expense->refOrder?->shop_id,
            $expense->date->toDateString(),
        );
    }
}
