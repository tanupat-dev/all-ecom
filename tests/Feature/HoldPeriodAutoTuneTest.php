<?php

/**
 * Hold Period auto-tune from Settlement Date history (Issue #68).
 *
 * Covers:
 *   – Median of an ODD sample (>= MIN_SAMPLE) overrides hold_period
 *   – Median of an EVEN sample averages the two middle values, round half up
 *   – Below MIN_SAMPLE settled Orders → manual hold_period stands (ADR 0005)
 *   – A Shop whose Orders never carry a Settlement Date (TikTok-style) →
 *     never reaches MIN_SAMPLE → manual value stands (never guessed)
 *   – After a tune, a fresh Order's Expected Payout Date uses the new period
 *   – The OrderObserver enqueues ONE deduped tune job per shop when settlement
 *     dates land (ShouldBeUnique, mirrors Issue #71)
 *   – Cross-tenant: tuning one tenant's Shop never reads another tenant's Orders
 */

use App\Actions\Accounting\ComputeExpectedPayoutDate;
use App\Actions\Accounting\TuneShopHoldPeriod;
use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Imports\NormalizedOrder;
use App\Jobs\TuneShopHoldPeriodJob;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A marketplace shop + one variant listed on it.
 *
 * @return array{Shop, Variant}
 */
function tuneShopWithVariant(Platform $platform = Platform::Shopee): array
{
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle('tune-'.uniqid(), $platform, $location);

    $sku = 'TUNE-'.uniqid();
    $product = app(CreateProduct::class)->handle('Item', [
        ['master_sku' => $sku, 'name' => 'default', 'list_price' => Money::fromBaht('100')],
    ]);
    app(CreateListing::class)->handle($shop, $product);

    return [$shop->load('settings'), $product->variants->firstOrFail()];
}

/** Import one completed marketplace order with the given anchor milestone set. */
function tuneOrder(Shop $shop, Variant $variant, string $platformOrderId, string $anchorCol, Carbon $anchorAt): Order
{
    app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: $platformOrderId,
        status: OrderStatus::Completed,
        lines: [['variant' => $variant, 'qty' => 1, 'unit_price' => Money::fromBaht('100')]],
        milestones: [$anchorCol => $anchorAt],
    ));

    return Order::query()->where('platform_order_id', $platformOrderId)->firstOrFail();
}

/**
 * Import a completed order and land a Settlement Date `$deltaDays` after its
 * anchor — one whole settled sample for the median.
 */
function settledOrder(Shop $shop, Variant $variant, string $id, string $anchorCol, int $deltaDays): Order
{
    $anchorAt = Carbon::parse('2026-03-01 00:00:00');
    $order = tuneOrder($shop, $variant, $id, $anchorCol, $anchorAt);
    $order->update(['settlement_date' => $anchorAt->copy()->addDays($deltaDays)]);

    return $order;
}

/** @param  list<int>  $deltas */
function seedSettledOrders(Shop $shop, Variant $variant, string $anchorCol, array $deltas): void
{
    foreach ($deltas as $i => $delta) {
        settledOrder($shop, $variant, uniqid('OD-').'-'.$i, $anchorCol, $delta);
    }
}

// ---------------------------------------------------------------------------
// Setup / teardown
// ---------------------------------------------------------------------------

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('TenantA');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 1. ODD sample median overrides hold_period
// ---------------------------------------------------------------------------

it('tunes hold_period to the median of an odd settled sample', function () {
    Queue::fake(); // suppress the observer's auto-tune; drive the Action directly

    [$shop, $variant] = tuneShopWithVariant(Platform::Shopee);
    expect($shop->settings()->firstOrFail()->hold_period)->toBe(7); // default

    // deltas sorted [2,4,10,12,14] → median 10
    seedSettledOrders($shop, $variant, 'completed_date', [10, 2, 14, 4, 12]);

    app(TuneShopHoldPeriod::class)->handle($shop);

    expect($shop->settings()->firstOrFail()->hold_period)->toBe(10);
});

// ---------------------------------------------------------------------------
// 2. EVEN sample median — two-middle average, round half up
// ---------------------------------------------------------------------------

it('averages the two middle values and rounds half up for an even sample', function () {
    Queue::fake();

    [$shop, $variant] = tuneShopWithVariant(Platform::Shopee);

    // sorted [5,6,8,9,11,12] → two middles 8 & 9 → 8.5 → round half up → 9
    seedSettledOrders($shop, $variant, 'completed_date', [8, 5, 12, 6, 11, 9]);

    app(TuneShopHoldPeriod::class)->handle($shop);

    // Pins the rounding DIRECTION: 8.5 resolves to 9 (half up), never 8.
    expect($shop->settings()->firstOrFail()->hold_period)->toBe(9);
});

// ---------------------------------------------------------------------------
// 3. Below MIN_SAMPLE → manual value stands
// ---------------------------------------------------------------------------

it('leaves hold_period untouched below MIN_SAMPLE settled orders', function () {
    Queue::fake();

    [$shop, $variant] = tuneShopWithVariant(Platform::Shopee);

    // 4 settled orders (MIN_SAMPLE is 5) — not enough signal to override
    seedSettledOrders($shop, $variant, 'completed_date', [20, 22, 24, 26]);

    app(TuneShopHoldPeriod::class)->handle($shop);

    expect($shop->settings()->firstOrFail()->hold_period)->toBe(7); // manual stands
});

// ---------------------------------------------------------------------------
// 4. No Settlement Date at all (TikTok-style) → never tuned
// ---------------------------------------------------------------------------

it('never tunes a shop whose orders carry no settlement date (TikTok-style)', function () {
    Queue::fake();

    // TikTok anchors on delivered_date; its export may omit Settlement Date,
    // so settlement_date stays null on every Order → never reaches MIN_SAMPLE.
    [$shop, $variant] = tuneShopWithVariant(Platform::Tiktok);
    expect($shop->settings()->firstOrFail()->payout_anchor)->toBe('delivered_date');

    // 8 delivered orders, every one WITHOUT a settlement_date
    foreach (range(1, 8) as $i) {
        tuneOrder($shop, $variant, "TT-{$i}", 'delivered_date', Carbon::parse('2026-03-01'));
    }

    app(TuneShopHoldPeriod::class)->handle($shop);

    expect($shop->settings()->firstOrFail()->hold_period)->toBe(7); // never guessed
});

// ---------------------------------------------------------------------------
// 5. After tune, a fresh order's Expected Payout Date uses the new period
// ---------------------------------------------------------------------------

it('uses the tuned hold_period for a freshly imported order expected payout date', function () {
    // No Queue::fake — let the sync observer auto-tune end-to-end.
    [$shop, $variant] = tuneShopWithVariant(Platform::Shopee);

    // 5 settled orders, all 12-day holds → median 12
    seedSettledOrders($shop, $variant, 'completed_date', [12, 12, 12, 12, 12]);

    expect($shop->settings()->firstOrFail()->hold_period)->toBe(12); // auto-tuned via observer

    $completedAt = Carbon::parse('2026-05-01 00:00:00');
    $fresh = tuneOrder($shop, $variant, 'SP-FRESH', 'completed_date', $completedAt);
    app(ComputeExpectedPayoutDate::class)->handle($fresh);
    $fresh->refresh();

    expect($fresh->expected_payout_date?->toDateString())->toBe('2026-05-13'); // completed + 12
});

// ---------------------------------------------------------------------------
// 6. Observer enqueues ONE deduped tune job per shop on settlement landing
// ---------------------------------------------------------------------------

it('enqueues a single deduped tune job per shop when settlement dates land', function () {
    [$shop, $variant] = tuneShopWithVariant(Platform::Shopee);

    $anchorAt = Carbon::parse('2026-03-01');
    $orders = collect(range(1, 4))->map(
        fn (int $i) => tuneOrder($shop, $variant, "SP-OBS-{$i}", 'completed_date', $anchorAt)
    );

    Queue::fake(); // watch only the settlement-landing writes below

    $tenantId = $shop->tenant_id;
    foreach ($orders as $order) {
        $order->update(['settlement_date' => $anchorAt->copy()->addDays(7)]);
    }

    $expectedKey = "{$tenantId}:{$shop->id}";

    Queue::assertPushed(TuneShopHoldPeriodJob::class,
        fn (TuneShopHoldPeriodJob $job) => $job->uniqueId() === $expectedKey);

    // All four settlement writes collapse to one dedup key (ShouldBeUnique).
    $keys = [];
    Queue::assertPushed(TuneShopHoldPeriodJob::class, function (TuneShopHoldPeriodJob $job) use (&$keys): bool {
        $keys[] = $job->uniqueId();

        return true;
    });
    expect(array_unique($keys))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// 7. Observer ignores a save that does not change settlement_date
// ---------------------------------------------------------------------------

it('does not enqueue a tune job when settlement_date is unchanged', function () {
    [$shop, $variant] = tuneShopWithVariant(Platform::Shopee);
    $order = tuneOrder($shop, $variant, 'SP-NOOP', 'completed_date', Carbon::parse('2026-03-01'));

    Queue::fake();

    // A non-settlement write (status only) must not enqueue a tune.
    $order->update(['tracking_number' => 'TRK-1']);

    Queue::assertNotPushed(TuneShopHoldPeriodJob::class);
});

// ---------------------------------------------------------------------------
// 8. Cross-tenant: tuning one tenant's shop never reads another tenant's orders
// ---------------------------------------------------------------------------

it('computes the median only from the current tenant orders', function () {
    Queue::fake();

    // Tenant A: 5 settled orders, all 10-day holds → median 10
    [$shopA, $variantA] = tuneShopWithVariant(Platform::Shopee);
    seedSettledOrders($shopA, $variantA, 'completed_date', [10, 10, 10, 10, 10]);

    // Tenant B: 5 settled orders, all 20-day holds → median 20
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('TenantB');
    app(TenantContext::class)->set($tenantB);

    [$shopB, $variantB] = tuneShopWithVariant(Platform::Shopee);
    seedSettledOrders($shopB, $variantB, 'completed_date', [20, 20, 20, 20, 20]);

    // Back to Tenant A and tune A's shop.
    app(TenantContext::class)->forget();
    $tenantA = Tenant::query()->where('name', 'TenantA')->firstOrFail();
    app(TenantContext::class)->set($tenantA);

    app(TuneShopHoldPeriod::class)->handle($shopA);

    // A tuned to its OWN median (10). Had B's 5 orders leaked in, the 10-order
    // median would be (10+20)/2 = 15 — so 10 proves the read stayed tenant-scoped.
    expect($shopA->settings()->firstOrFail()->hold_period)->toBe(10);

    // B was never tuned — still its manual default.
    app(TenantContext::class)->forget();
    app(TenantContext::class)->set($tenantB);
    expect($shopB->settings()->firstOrFail()->hold_period)->toBe(7);
});
