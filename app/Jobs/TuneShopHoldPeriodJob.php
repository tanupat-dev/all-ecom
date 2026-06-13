<?php

namespace App\Jobs;

use App\Actions\Accounting\TuneShopHoldPeriod;
use App\Models\Shop;
use App\Tenancy\RestoreTenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-tunes one Shop's Hold Period off the queue (Issue #68). Enqueued by the
 * OrderObserver whenever a Settlement Date lands on an Order. ShouldBeUnique
 * keyed by tenant+shop so a 500-row accounting import that lands settlement
 * dates on many Orders of the same Shop enqueues this once, not 500 times —
 * still-queued duplicates collapse. Correctness rests on TuneShopHoldPeriod
 * being idempotent (it recomputes the median from scratch each run), NOT on
 * the dedupe, which is purely a throughput optimisation.
 *
 * A queue worker has no request, so RestoreTenantContext puts the tenant back
 * before the RLS-protected reads (which fail closed otherwise).
 */
class TuneShopHoldPeriodJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $shopId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->shopId}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext($this->tenantId)];
    }

    public function handle(TuneShopHoldPeriod $tune): void
    {
        $tune->handle(Shop::query()->findOrFail($this->shopId));
    }
}
