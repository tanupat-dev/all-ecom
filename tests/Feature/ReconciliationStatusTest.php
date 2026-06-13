<?php

use App\Actions\Accounting\ComputeReconciliationStatus;
use App\Actions\Accounting\UpsertAccountingCycle;
use App\Actions\Catalog\CreateProduct;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\AccountingLineCategory;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ReconciliationStatus;
use App\Filament\Resources\ReconciliationMismatches\ReconciliationMismatchResource;
use App\Models\Location;
use App\Models\Order;
use App\Models\PlatformFeeProfile;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    app(TenantContext::class)->set(app(CreateTenant::class)->handle('Recon'));
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function reconShop(string $name = 'Shopee'): Shop
{
    return app(CreateShop::class)->handle($name, Platform::Shopee, Location::query()->firstOrFail());
}

function reconPosShop(): Shop
{
    return app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, Location::query()->firstOrFail());
}

function reconVariant(string $priceBaht = '100.00'): Variant
{
    return app(CreateProduct::class)
        ->handle('สินค้า '.uniqid(), [['master_sku' => 'SKU-'.uniqid(), 'list_price' => Money::fromBaht($priceBaht)]])
        ->variants->firstOrFail();
}

/**
 * A bare marketplace Order. Pass satang to pre-seed Expected / Actual Net.
 */
function reconOrder(Shop $shop, ?int $expectedSatang = null, ?int $actualSatang = null): Order
{
    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => $shop->platform_type,
        'platform_order_id' => 'SP-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('0'),
        'expected_net' => $expectedSatang === null ? null : Money::fromSatang($expectedSatang),
        'actual_net' => $actualSatang === null ? null : Money::fromSatang($actualSatang),
    ]);
}

it('grades NotYetPaid when no accounting has landed (actual_net null)', function () {
    $order = reconOrder(reconShop(), expectedSatang: 100_000, actualSatang: null);

    app(ComputeReconciliationStatus::class)->handle($order);

    expect($order->refresh()->reconciliation_status)->toBe(ReconciliationStatus::NotYetPaid);
});

it('grades NotYetPaid when there is no Expected Net baseline to check against', function () {
    $order = reconOrder(reconShop(), expectedSatang: null, actualSatang: 100_000);

    app(ComputeReconciliationStatus::class)->handle($order);

    expect($order->refresh()->reconciliation_status)->toBe(ReconciliationStatus::NotYetPaid);
});

it('pins the ±threshold boundary in satang (฿1 default = 100 satang)', function () {
    $shop = reconShop();

    // Expected Net = 100_000 satang; Shop default Mismatch Threshold = 100.
    // Exactly at the threshold (diff 100) is still PaidOk; one satang past it
    // is PaidMismatch — both directions (Platform paid short AND over).
    $cases = [
        [100_100, ReconciliationStatus::PaidOk],       // +100 → boundary, ok
        [100_101, ReconciliationStatus::PaidMismatch],  // +101 → just past, mismatch
        [99_900, ReconciliationStatus::PaidOk],         // −100 → boundary, ok
        [99_899, ReconciliationStatus::PaidMismatch],   // −101 → just past, mismatch
    ];

    foreach ($cases as [$actualSatang, $expectedStatus]) {
        $order = reconOrder($shop, expectedSatang: 100_000, actualSatang: $actualSatang);

        app(ComputeReconciliationStatus::class)->handle($order);

        expect($order->refresh()->reconciliation_status)->toBe($expectedStatus);
    }
});

it('flips a paid_ok Order to paid_mismatch when a later cycle posts an extra deduction (ADR 0007)', function () {
    $shop = reconShop();

    // Expected Net 100_000 satang; default threshold 100. First cycle settles
    // the sale within threshold → paid_ok.
    $order = reconOrder($shop, expectedSatang: 100_000, actualSatang: null);

    app(UpsertAccountingCycle::class)->handle($order, '2026-05', [
        ['source_field' => 'รายได้', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromSatang(100_050)],
    ]);

    expect($order->refresh()->actual_net?->satang)->toBe(100_050)
        ->and($order->reconciliation_status)->toBe(ReconciliationStatus::PaidOk);

    // A later cycle posts a return-shipping deduction of −500 satang. Actual
    // Net = 100_050 − 500 = 99_550; |99_550 − 100_000| = 450 > 100 → mismatch.
    app(UpsertAccountingCycle::class)->handle($order, '2026-06', [
        ['source_field' => 'ค่าส่งคืน', 'category' => AccountingLineCategory::ShippingReturn, 'amount' => Money::fromSatang(-500)],
    ]);

    expect($order->refresh()->actual_net?->satang)->toBe(99_550)
        ->and($order->reconciliation_status)->toBe(ReconciliationStatus::PaidMismatch);
});

it('re-grades reconciliation when a Fee Profile change shifts Expected Net (via the queued Job)', function () {
    $shop = reconShop();
    // Order with line_total 1000.00 = 100_000 satang Effective Price.
    $order = Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => $shop->platform_type,
        'platform_order_id' => 'SP-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('0'),
    ]);
    $order->lines()->create([
        'variant_id' => reconVariant('1000.00')->id,
        'qty' => 1,
        'unit_price' => Money::fromBaht('1000.00'),
        'line_total' => Money::fromBaht('1000.00'),
    ]);

    // A 1% commission → Expected Net = 100_000 − 1_000 = 99_000. Saving the
    // profile dispatches RecomputeShopExpectedNet (sync in tests), which
    // recomputes Expected Net AND re-grades reconciliation.
    $profile = PlatformFeeProfile::query()->create([
        'shop_id' => $shop->id,
        'category' => AccountingLineCategory::Commission,
        'rate_bps' => 100,
    ]);

    expect($order->refresh()->expected_net?->satang)->toBe(99_000);

    // Actual Net lands at 99_000 (exact) → paid_ok.
    app(UpsertAccountingCycle::class)->handle($order, '2026-05', [
        ['source_field' => 'รายได้', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromSatang(99_000)],
    ]);

    expect($order->refresh()->reconciliation_status)->toBe(ReconciliationStatus::PaidOk);

    // Raise commission to 10% → Expected Net = 90_000; |99_000 − 90_000| =
    // 9_000 > 100 → the re-grade flips it to paid_mismatch without any new
    // accounting import.
    $profile->update(['rate_bps' => 1000]);

    expect($order->refresh()->expected_net?->satang)->toBe(90_000)
        ->and($order->reconciliation_status)->toBe(ReconciliationStatus::PaidMismatch);
});

it('refuses a POS Order and leaves its Reconciliation Status null', function () {
    $order = reconOrder(reconPosShop(), expectedSatang: 100_000, actualSatang: 100_000);

    expect(fn () => app(ComputeReconciliationStatus::class)->handle($order))
        ->toThrow(InvalidArgumentException::class, 'A POS Order has no Reconciliation Status');

    expect($order->refresh()->reconciliation_status)->toBeNull();
});

it('shows each tenant only its own mismatched Orders in the Mismatch list', function () {
    $context = app(TenantContext::class);
    // Tenant A is the only tenant so far (created in beforeEach).
    $tenantA = Tenant::query()->firstOrFail();

    // Tenant A (from beforeEach) gets one mismatched Order.
    $shopA = reconShop();
    $orderA = reconOrder($shopA, expectedSatang: 100_000, actualSatang: 100_500);
    app(ComputeReconciliationStatus::class)->handle($orderA);
    expect($orderA->refresh()->reconciliation_status)->toBe(ReconciliationStatus::PaidMismatch);

    // A second tenant with its own mismatched Order.
    $tenantB = app(CreateTenant::class)->handle('Recon-B');
    $context->set($tenantB);
    $shopB = reconShop();
    $orderB = reconOrder($shopB, expectedSatang: 100_000, actualSatang: 100_500);
    app(ComputeReconciliationStatus::class)->handle($orderB);
    expect($orderB->refresh()->reconciliation_status)->toBe(ReconciliationStatus::PaidMismatch);

    // The Mismatch list query, tenant-scoped via BelongsToTenant, never leaks
    // the other tenant's row.
    $context->set($tenantB);
    expect(ReconciliationMismatchResource::getEloquentQuery()->pluck('id')->all())->toBe([$orderB->id]);

    $context->set($tenantA);
    expect(ReconciliationMismatchResource::getEloquentQuery()->pluck('id')->all())->toBe([$orderA->id]);
});
