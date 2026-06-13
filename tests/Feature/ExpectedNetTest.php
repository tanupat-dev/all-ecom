<?php

use App\Actions\Accounting\ComputeExpectedNet;
use App\Actions\Catalog\CreateProduct;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\AccountingLineCategory;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Models\Location;
use App\Models\Order;
use App\Models\PlatformFeeProfile;
use App\Models\Shop;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function feeShop(string $name = 'Shopee'): Shop
{
    return app(CreateShop::class)->handle($name, Platform::Shopee, Location::query()->firstOrFail());
}

function feePosShop(): Shop
{
    return app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, Location::query()->firstOrFail());
}

function feeVariant(string $priceBaht): Variant
{
    return app(CreateProduct::class)
        ->handle('สินค้า '.uniqid(), [['master_sku' => 'SKU-'.uniqid(), 'list_price' => Money::fromBaht($priceBaht)]])
        ->variants->firstOrFail();
}

/**
 * @param  array<int, array{unit_price: string, qty?: int, line_total: string}>  $lines
 */
function feeOrder(Shop $shop, array $lines): Order
{
    $order = Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => $shop->platform_type,
        'platform_order_id' => 'SP-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('0'),
    ]);

    foreach ($lines as $line) {
        $order->lines()->create([
            'variant_id' => feeVariant($line['unit_price'])->id,
            'qty' => $line['qty'] ?? 1,
            'unit_price' => Money::fromBaht($line['unit_price']),
            'line_total' => Money::fromBaht($line['line_total']),
        ]);
    }

    return $order;
}

it('computes Expected Net for a multi-line order with percentage and fixed fees, in integer satang', function () {
    $shop = feeShop();

    // Effective Price total = 600.00 + 495.00 = 1095.00 = 109_500 satang.
    $order = feeOrder($shop, [
        ['unit_price' => '600.00', 'line_total' => '600.00'],
        ['unit_price' => '495.00', 'line_total' => '495.00'],
    ]);

    // Commission 3.21% (321 bps); Payment fee 2.00% (200 bps) + ฿3.00 flat.
    PlatformFeeProfile::query()->create([
        'shop_id' => $shop->id,
        'category' => AccountingLineCategory::Commission,
        'rate_bps' => 321,
    ]);
    PlatformFeeProfile::query()->create([
        'shop_id' => $shop->id,
        'category' => AccountingLineCategory::PaymentFee,
        'rate_bps' => 200,
        'fixed_satang' => 300,
    ]);

    app(ComputeExpectedNet::class)->handle($order);

    // Commission: 109500*321/10000 = 3514.95 → half-up 3515.
    // Payment:    109500*200/10000 = 2190.05 → 2190, + 300 flat = 2490.
    // Expected Net = 109500 − (3515 + 2490) = 103_495 satang.
    expect($order->refresh()->expected_net)->toBeInstanceOf(Money::class)
        ->and($order->expected_net?->satang)->toBe(103_495);
});

it('pins the rounding direction at half-up on the fee, not toward zero', function () {
    $shop = feeShop();

    // 109_500 satang at 321 bps = 3514.95 satang — the .95 must round UP to
    // 3515 (half-up), never down to 3514 (truncation).
    $order = feeOrder($shop, [['unit_price' => '1095.00', 'line_total' => '1095.00']]);

    PlatformFeeProfile::query()->create([
        'shop_id' => $shop->id,
        'category' => AccountingLineCategory::Commission,
        'rate_bps' => 321,
    ]);

    app(ComputeExpectedNet::class)->handle($order);

    // Fee 3515 (not 3514) → Expected Net = 109500 − 3515 = 105_985.
    expect($order->refresh()->expected_net?->satang)->toBe(105_985);
});

it('sums line_total across lines as the Effective Price total (no unit_price fallback needed)', function () {
    $shop = feeShop();

    // line_total already nets any line discount → it IS the Effective Price.
    // 100.00 + 250.00 = 350.00 = 35_000 satang.
    $order = feeOrder($shop, [
        ['unit_price' => '120.00', 'line_total' => '100.00'], // 20.00 line discount
        ['unit_price' => '250.00', 'line_total' => '250.00'],
    ]);

    PlatformFeeProfile::query()->create([
        'shop_id' => $shop->id,
        'category' => AccountingLineCategory::Commission,
        'rate_bps' => 1000, // 10%
    ]);

    app(ComputeExpectedNet::class)->handle($order);

    // Fee = 35000*1000/10000 = 3500. Expected Net = 35000 − 3500 = 31_500.
    expect($order->refresh()->expected_net?->satang)->toBe(31_500);
});

it('refuses a POS Order and never assigns it an Expected Net', function () {
    $order = feeOrder(feePosShop(), [['unit_price' => '500.00', 'line_total' => '500.00']]);

    expect(fn () => app(ComputeExpectedNet::class)->handle($order))
        ->toThrow(InvalidArgumentException::class, 'A POS Order has no Expected Net');

    expect($order->refresh()->expected_net)->toBeNull();
});

it('recomputes a Shop\'s marketplace orders when its Fee Profile is saved, via the queued Job', function () {
    $shop = feeShop();
    $order = feeOrder($shop, [['unit_price' => '1000.00', 'line_total' => '1000.00']]); // 100_000 satang

    expect($order->refresh()->expected_net)->toBeNull();

    // Saving a profile dispatches RecomputeShopExpectedNet (sync in tests),
    // which runs ComputeExpectedNet over the Shop's marketplace orders — no
    // request-time scan; the value lands on the denormalized column.
    $profile = PlatformFeeProfile::query()->create([
        'shop_id' => $shop->id,
        'category' => AccountingLineCategory::Commission,
        'rate_bps' => 500, // 5%
    ]);

    // Fee = 100000*500/10000 = 5000 → Expected Net = 95_000.
    expect($order->refresh()->expected_net?->satang)->toBe(95_000);

    // Editing the rate recomputes again.
    $profile->update(['rate_bps' => 1000]); // 10%

    // Fee = 10000 → Expected Net = 90_000.
    expect($order->refresh()->expected_net?->satang)->toBe(90_000);
});

it('passes the cross-tenant isolation harness (platform fee profiles)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): PlatformFeeProfile {
        $sequence++;
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle("Shopee-{$sequence}", Platform::Shopee, $location);

        return PlatformFeeProfile::query()->create([
            'shop_id' => $shop->id,
            'category' => AccountingLineCategory::Commission,
            'rate_bps' => 321,
        ]);
    });
});
