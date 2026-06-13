<?php

namespace App\Observers\Concerns;

use App\Jobs\RecomputeDailyPnlJob;

/**
 * Shared dirty-marking for the Daily P&L observers (Issue #71). Enqueues a
 * RecomputeDailyPnlJob for the affected (tenant, shop, date) bucket, no-opping
 * when the bucket cannot be resolved (e.g. an Order with no created_date, an
 * Expense with no ref_order, an open Shift). The job dedupes per bucket and
 * recomputes idempotently.
 *
 * WHY OBSERVERS, NOT EDITS TO THE MONEY ACTIONS: an observer fires on EVERY
 * write path into the underlying table — including a re-import that replaces a
 * cycle's lines (ADR 0007) and any future writer — without touching the
 * correctness-critical money Actions (UpsertAccountingCycle, CheckoutPosSale,
 * …). One hook, every path covered, zero blast radius on the money math.
 */
trait MarksDailyPnlDirty
{
    private function dispatchRecompute(?int $tenantId, ?int $shopId, ?string $date): void
    {
        if ($tenantId === null || $shopId === null || $date === null) {
            return;
        }

        RecomputeDailyPnlJob::dispatch($tenantId, $shopId, $date);
    }
}
