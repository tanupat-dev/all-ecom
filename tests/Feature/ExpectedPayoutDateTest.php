<?php

/**
 * Expected Payout Date + overdue detection (Issue #67).
 *
 * Covers:
 *   – Shopee: anchors on completed_date
 *   – TikTok/Lazada: anchors on delivered_date
 *   – Null anchor milestone → null expected_payout_date
 *   – Changing payout_anchor / hold_period shifts the computed date
 *   – Overdue list: past-due NotYetPaid Orders appear; paid_ok / future drop off
 *   – POS Order → Action refuses
 *   – Cross-tenant: overdue list is tenant-scoped
 */

use App\Actions\Accounting\ComputeExpectedPayoutDate;
use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Enums\ReconciliationStatus;
use App\Filament\Resources\OverduePayouts\OverduePayoutResource;
use App\Imports\NormalizedOrder;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a marketplace shop + one variant that is listed on it.
 * Returns [Shop, Variant].
 *
 * @return array{Shop, Variant}
 */
function payoutShopWithVariant(Platform $platform = Platform::Shopee): array
{
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle('shop-'.uniqid(), $platform, $location);

    $sku = 'PAYOUT-'.uniqid();
    $product = app(CreateProduct::class)->handle('Item', [
        ['master_sku' => $sku, 'name' => 'default', 'list_price' => Money::fromBaht('100')],
    ]);
    app(CreateListing::class)->handle($shop, $product);

    $variant = $product->variants->firstOrFail();

    return [$shop->load('settings'), $variant];
}

/**
 * Import one marketplace order through the full pipeline so milestones are
 * applied and ComputeExpectedPayoutDate is wired correctly.
 *
 * @param  array<string, DateTimeInterface|null>  $milestones
 */
function importPayoutOrder(
    Shop $shop,
    Variant $variant,
    string $platformOrderId,
    OrderStatus $status = OrderStatus::Completed,
    array $milestones = [],
): Order {
    app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: $platformOrderId,
        status: $status,
        lines: [['variant' => $variant, 'qty' => 1, 'unit_price' => Money::fromBaht('100')]],
        milestones: $milestones,
    ));

    return Order::query()->where('platform_order_id', $platformOrderId)->firstOrFail();
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
});

// ---------------------------------------------------------------------------
// 1. Shopee: anchors on completed_date
// ---------------------------------------------------------------------------

it('computes expected_payout_date from completed_date for a Shopee order', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    [$shop, $variant] = payoutShopWithVariant(Platform::Shopee);

    // Default hold_period = 7 days (set by CreateShop), payout_anchor = completed_date
    expect($shop->settings()->firstOrFail()->payout_anchor)->toBe('completed_date')
        ->and($shop->settings()->firstOrFail()->hold_period)->toBe(7);

    $completedAt = Carbon::parse('2026-05-20 10:00:00');

    $order = importPayoutOrder(
        $shop, $variant, 'SP-101', OrderStatus::Completed,
        ['completed_date' => $completedAt],
    );

    expect($order->expected_payout_date)->not->toBeNull()
        ->and($order->expected_payout_date?->toDateString())->toBe('2026-05-27');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 2. TikTok: anchors on delivered_date
// ---------------------------------------------------------------------------

it('computes expected_payout_date from delivered_date for a TikTok order', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    [$shop, $variant] = payoutShopWithVariant(Platform::Tiktok);

    expect($shop->settings()->firstOrFail()->payout_anchor)->toBe('delivered_date');

    $deliveredAt = Carbon::parse('2026-05-18 08:00:00');

    $order = importPayoutOrder(
        $shop, $variant, 'TT-201', OrderStatus::Completed,
        ['delivered_date' => $deliveredAt],
    );

    expect($order->expected_payout_date)->not->toBeNull()
        ->and($order->expected_payout_date?->toDateString())->toBe('2026-05-25');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 3. Lazada: also anchors on delivered_date
// ---------------------------------------------------------------------------

it('computes expected_payout_date from delivered_date for a Lazada order', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    [$shop, $variant] = payoutShopWithVariant(Platform::Lazada);

    expect($shop->settings()->firstOrFail()->payout_anchor)->toBe('delivered_date');

    $deliveredAt = Carbon::parse('2026-05-10 09:00:00');

    $shop->settings()->firstOrFail()->update(['hold_period' => 14]);

    $order = importPayoutOrder(
        $shop, $variant, 'LZ-301', OrderStatus::Completed,
        ['delivered_date' => $deliveredAt],
    );

    expect($order->expected_payout_date)->not->toBeNull()
        ->and($order->expected_payout_date?->toDateString())->toBe('2026-05-24');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 4. Null anchor → null expected_payout_date
// ---------------------------------------------------------------------------

it('leaves expected_payout_date null when the anchor milestone is not yet set', function () {
    [$shop, $variant] = payoutShopWithVariant(Platform::Shopee);

    // Import without completed_date (goods not yet finalised)
    $order = importPayoutOrder(
        $shop, $variant, 'SP-NULL-1', OrderStatus::AwaitingPack,
        [], // no milestones
    );

    expect($order->expected_payout_date)->toBeNull();
});

// ---------------------------------------------------------------------------
// 5. Changing payout_anchor / hold_period shifts the computed date
// ---------------------------------------------------------------------------

it('shifts expected_payout_date when the Shop payout_anchor or hold_period changes', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    [$shop, $variant] = payoutShopWithVariant(Platform::Shopee);

    $completedAt = Carbon::parse('2026-05-20 10:00:00');

    $order = importPayoutOrder(
        $shop, $variant, 'SP-SHIFT-1', OrderStatus::Completed,
        ['completed_date' => $completedAt],
    );

    // Original: completed + 7 days
    expect($order->expected_payout_date?->toDateString())->toBe('2026-05-27');

    // Change the hold_period and recompute
    $shop->settings()->firstOrFail()->update(['hold_period' => 14]);
    $order->refresh();

    app(ComputeExpectedPayoutDate::class)->handle($order);
    $order->refresh();

    expect($order->expected_payout_date?->toDateString())->toBe('2026-06-03');

    // Change the payout_anchor to delivered_date (misconfiguration scenario)
    $shop->settings()->firstOrFail()->update(['payout_anchor' => 'delivered_date']);
    $deliveredAt = Carbon::parse('2026-05-15 08:00:00');
    $order->update(['delivered_date' => $deliveredAt]);

    app(ComputeExpectedPayoutDate::class)->handle($order);
    $order->refresh();

    // Now: delivered + 14 days
    expect($order->expected_payout_date?->toDateString())->toBe('2026-05-29');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 6. Overdue list: shows past-due NotYetPaid Orders
// ---------------------------------------------------------------------------

it('overdue query includes past-due NotYetPaid orders', function () {
    Carbon::setTestNow('2026-06-10 12:00:00');

    [$shop, $variant] = payoutShopWithVariant(Platform::Shopee);

    // Order with expected_payout_date in the past and not yet paid
    $order = importPayoutOrder(
        $shop, $variant, 'SP-OD-1', OrderStatus::Completed,
        ['completed_date' => Carbon::parse('2026-05-01 10:00:00')],
    );
    // Make sure reconciliation_status is not_yet_paid
    $order->update(['reconciliation_status' => ReconciliationStatus::NotYetPaid]);
    $order->refresh();

    expect($order->expected_payout_date)->not->toBeNull()
        ->and($order->expected_payout_date?->isPast())->toBeTrue();

    $overdueQuery = OverduePayoutResource::getEloquentQuery();
    $overdueIds = $overdueQuery->pluck('id');

    expect($overdueIds)->toContain($order->id);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 7. Order drops off when reconciliation_status changes to paid_ok
// ---------------------------------------------------------------------------

it('overdue list drops an order when reconciliation_status becomes paid_ok', function () {
    Carbon::setTestNow('2026-06-10 12:00:00');

    [$shop, $variant] = payoutShopWithVariant(Platform::Shopee);

    $order = importPayoutOrder(
        $shop, $variant, 'SP-PAID-1', OrderStatus::Completed,
        ['completed_date' => Carbon::parse('2026-05-01 10:00:00')],
    );
    $order->update(['reconciliation_status' => ReconciliationStatus::NotYetPaid]);

    $overdueQuery = OverduePayoutResource::getEloquentQuery();
    expect($overdueQuery->pluck('id'))->toContain($order->id);

    // Flip to paid_ok (accounting landed)
    $order->update(['reconciliation_status' => ReconciliationStatus::PaidOk]);

    $overdueQuery2 = OverduePayoutResource::getEloquentQuery();
    expect($overdueQuery2->pluck('id'))->not->toContain($order->id);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 8. Order with future expected_payout_date does not appear in overdue list
// ---------------------------------------------------------------------------

it('overdue list excludes orders whose expected_payout_date is in the future', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    [$shop, $variant] = payoutShopWithVariant(Platform::Shopee);

    // completed_date just yesterday — hold_period 7 → payout due in 6 days
    $order = importPayoutOrder(
        $shop, $variant, 'SP-FUTURE-1', OrderStatus::Completed,
        ['completed_date' => Carbon::parse('2026-05-31 10:00:00')],
    );
    $order->update(['reconciliation_status' => ReconciliationStatus::NotYetPaid]);

    $overdueIds = OverduePayoutResource::getEloquentQuery()->pluck('id');
    expect($overdueIds)->not->toContain($order->id);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 9. POS Order → Action refuses
// ---------------------------------------------------------------------------

it('refuses a POS order with InvalidArgumentException', function () {
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $posShop = app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, $location);

    $posOrder = Order::query()->create([
        'shop_id' => $posShop->id,
        'platform_type' => PlatformType::Pos,
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);

    expect(fn () => app(ComputeExpectedPayoutDate::class)->handle($posOrder))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// 10. Cross-tenant isolation
// ---------------------------------------------------------------------------

it('overdue list shows only the current tenant overdue orders', function () {
    Carbon::setTestNow('2026-06-10 12:00:00');

    // Tenant A (current context from beforeEach)
    [$shopA, $variantA] = payoutShopWithVariant(Platform::Shopee);
    $orderA = importPayoutOrder(
        $shopA, $variantA, 'SP-A-1', OrderStatus::Completed,
        ['completed_date' => Carbon::parse('2026-05-01 10:00:00')],
    );
    $orderA->update(['reconciliation_status' => ReconciliationStatus::NotYetPaid]);

    // Switch to Tenant B
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('TenantB');
    app(TenantContext::class)->set($tenantB);

    [$shopB, $variantB] = payoutShopWithVariant(Platform::Shopee);
    $orderB = importPayoutOrder(
        $shopB, $variantB, 'SP-B-1', OrderStatus::Completed,
        ['completed_date' => Carbon::parse('2026-05-01 10:00:00')],
    );
    $orderB->update(['reconciliation_status' => ReconciliationStatus::NotYetPaid]);

    // While in Tenant B context — only B's order visible
    $overdueIdsB = OverduePayoutResource::getEloquentQuery()->pluck('id');
    expect($overdueIdsB)->toContain($orderB->id)
        ->and($overdueIdsB)->not->toContain($orderA->id);

    // Switch back to Tenant A — only A's order visible
    app(TenantContext::class)->forget();
    $tenantA = Tenant::query()->where('name', 'TenantA')->firstOrFail();
    app(TenantContext::class)->set($tenantA);

    $overdueIdsA = OverduePayoutResource::getEloquentQuery()->pluck('id');
    expect($overdueIdsA)->toContain($orderA->id)
        ->and($overdueIdsA)->not->toContain($orderB->id);

    Carbon::setTestNow();
});
