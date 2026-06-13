<?php

namespace App\Jobs;

use App\Actions\Accounting\RecomputeDailyPnl;
use App\Tenancy\RestoreTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recomputes one (shop, date) Daily P&L rollup off the queue (Issue #71).
 * Enqueued by the dirty-marking observers (AccountingEntryLine / Payment /
 * Expense / Shift) whenever a write changes a bucket. ShouldBeUnique keyed by
 * tenant+shop+date so a 500-row import chunk that touches the same bucket
 * enqueues this once, not 500 times — while still-queued duplicates collapse.
 * Correctness rests on RecomputeDailyPnl being idempotent (the upsert), NOT on
 * the dedupe, which is purely a throughput optimisation.
 *
 * A queue worker has no request, so RestoreTenantContext puts the tenant back
 * before the RLS-protected reads (which fail closed otherwise).
 */
class RecomputeDailyPnlJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $shopId,
        public readonly string $date,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->shopId}:{$this->date}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext($this->tenantId)];
    }

    public function handle(RecomputeDailyPnl $recompute): void
    {
        $recompute->handle($this->shopId, CarbonImmutable::parse($this->date));
    }
}
