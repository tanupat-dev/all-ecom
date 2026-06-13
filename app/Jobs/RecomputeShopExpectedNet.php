<?php

namespace App\Jobs;

use App\Actions\Accounting\ComputeExpectedNet;
use App\Actions\Accounting\ComputeReconciliationStatus;
use App\Enums\PlatformType;
use App\Models\Order;
use App\Tenancy\RestoreTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recomputes Expected Net for every marketplace Order of one Shop after its
 * Platform Fee Profile changed (Issue #65). Dispatched from the
 * PlatformFeeProfile saved/deleted events — never scans Orders at request
 * time. Chunked so a Shop with many Orders does not load them all at once;
 * the per-order math lives in ComputeExpectedNet.
 *
 * POS Shops carry no Fee Profile and ComputeExpectedNet refuses a POS Order,
 * so the query is filtered to marketplace Orders defensively.
 *
 * A queue worker has no request, so RestoreTenantContext puts the Shop's
 * Tenant back before any RLS-protected read (which fails closed otherwise).
 */
class RecomputeShopExpectedNet implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $shopId,
        public readonly int $tenantId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext($this->tenantId)];
    }

    public function handle(
        ComputeExpectedNet $computeExpectedNet,
        ComputeReconciliationStatus $computeReconciliationStatus,
    ): void {
        Order::query()
            ->where('shop_id', $this->shopId)
            ->where('platform_type', PlatformType::Marketplace)
            ->chunkById(200, function (Collection $orders) use ($computeExpectedNet, $computeReconciliationStatus): void {
                foreach ($orders as $order) {
                    $computeExpectedNet->handle($order);

                    // A changed estimate re-grades reconciliation: an Order
                    // already paid (Actual Net present) can flip ok↔mismatch
                    // when the expected baseline shifts (ADR 0007).
                    $computeReconciliationStatus->handle($order);
                }
            });
    }
}
