<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Claims\ComputeExpectedShipping;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('ExpectedShippingTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A marketplace Shop with its expected_shipping_rate set to $rate (null = the
 * CreateShop default of "no expectation"). Loosely typed so the malformed-tier
 * cases can pass intentionally-invalid shapes.
 *
 * @param  array<array-key, mixed>|null  $rate
 */
function esShop(?array $rate): Shop
{
    $shop = app(CreateShop::class)->handle('ES Shop '.uniqid(), Platform::Shopee, Location::query()->firstOrFail());
    $shop->settings()->firstOrFail()->update(['expected_shipping_rate' => $rate]);

    return $shop->refresh();
}

/**
 * A Variant carrying the given package weight/dimensions (any key omitted
 * stays null).
 *
 * @param  array{package_weight_g?: int|null, package_length_mm?: int|null, package_width_mm?: int|null, package_height_mm?: int|null}  $pkg
 */
function esVariant(array $pkg): Variant
{
    $product = app(CreateProduct::class)->handle('ES Product '.uniqid(), [
        array_merge(['master_sku' => 'ES-'.uniqid(), 'list_price' => Money::fromBaht('100')], $pkg),
    ]);

    return $product->variants->firstOrFail();
}

/**
 * An Order on $shop with the given lines.
 *
 * @param  list<array{variant: Variant, qty: int}>  $lines
 */
function esOrder(Shop $shop, array $lines): Order
{
    $order = Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => 'ES-ORD-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);

    foreach ($lines as $line) {
        $order->lines()->create([
            'variant_id' => $line['variant']->id,
            'qty' => $line['qty'],
            'unit_price' => Money::fromBaht('100'),
            'line_total' => Money::fromBaht('100'),
        ]);
    }

    return $order->refresh();
}

/**
 * The standard 3-tier rate used across the tier-lookup cases.
 *
 * @return list<array{up_to_g: int, fee: int}>
 */
function esRate(): array
{
    return [
        ['up_to_g' => 500, 'fee' => 3000],
        ['up_to_g' => 1000, 'fee' => 5000],
        ['up_to_g' => 2000, 'fee' => 8000],
    ];
}

// ---------------------------------------------------------------------------
// Tier lookup
// ---------------------------------------------------------------------------

it('lands in the correct tier by chargeable weight', function () {
    $shop = esShop(esRate());
    // dims tiny so volumetric < weight; chargeable = 400g → first tier (≤500).
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 400, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order)?->satang)->toBe(3000);
});

it('uses the highest tier when chargeable weight exceeds every tier', function () {
    $shop = esShop(esRate());
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 5000, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order)?->satang)->toBe(8000);
});

it('uses the tier at an exact up_to_g boundary (inclusive), pinned in satang', function () {
    $shop = esShop(esRate());
    // chargeable exactly 500g → first tier (up_to_g 500 ≥ 500) → 3000 satang.
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 500, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order)?->satang)->toBe(3000);
});

// ---------------------------------------------------------------------------
// max(actual, volumetric) + the 5000 divisor
// ---------------------------------------------------------------------------

it('picks volumetric weight for a bulky-but-light Variant', function () {
    $shop = esShop(esRate());
    // 200×200×200 mm = 8,000,000 mm³ / 5000 = 1600g >> 100g actual → 1600g.
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 100, 'package_length_mm' => 200, 'package_width_mm' => 200, 'package_height_mm' => 200,
    ]), 'qty' => 1]]);

    // 1600g → tier ≤2000 → 8000 satang.
    expect(app(ComputeExpectedShipping::class)->handle($order)?->satang)->toBe(8000);
});

it('picks actual weight for a dense Variant', function () {
    $shop = esShop(esRate());
    // 100×100×100 mm = 1,000,000 / 5000 = 200g; actual 1500g wins → 1500g.
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 1500, 'package_length_mm' => 100, 'package_width_mm' => 100, 'package_height_mm' => 100,
    ]), 'qty' => 1]]);

    // 1500g → tier ≤2000 → 8000 satang.
    expect(app(ComputeExpectedShipping::class)->handle($order)?->satang)->toBe(8000);
});

it('applies the 5000 divisor with intdiv (floor) for volumetric grams', function () {
    $shop = esShop([['up_to_g' => 400, 'fee' => 2000], ['up_to_g' => 1000, 'fee' => 6000]]);
    // 170×100×100 = 1,700,000 / 5000 = 340g; actual 100g → chargeable 340g.
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 100, 'package_length_mm' => 170, 'package_width_mm' => 100, 'package_height_mm' => 100,
    ]), 'qty' => 1]]);

    // 340g → first tier (≤400) → 2000 satang.
    expect(app(ComputeExpectedShipping::class)->handle($order)?->satang)->toBe(2000);
});

// ---------------------------------------------------------------------------
// Per-unit-additive across lines and quantity (ADR 0022 §2)
// ---------------------------------------------------------------------------

it('sums chargeable weight per-unit-additive across lines and quantity', function () {
    $shop = esShop(esRate());
    $dense = esVariant(['package_weight_g' => 300, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10]);
    $other = esVariant(['package_weight_g' => 200, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10]);

    // 300×2 + 200×1 = 800g → tier ≤1000 → 5000 satang.
    $order = esOrder($shop, [['variant' => $dense, 'qty' => 2], ['variant' => $other, 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order)?->satang)->toBe(5000);
});

// ---------------------------------------------------------------------------
// Fail-safe: missing inputs → null (never guess)
// ---------------------------------------------------------------------------

it('returns null when expected_shipping_rate is null', function () {
    $shop = esShop(null);
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 400, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order))->toBeNull();
});

it('returns null when expected_shipping_rate is empty', function () {
    $shop = esShop([]);
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 400, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order))->toBeNull();
});

it('returns null when a line Variant has no weight', function () {
    $shop = esShop(esRate());
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => null, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order))->toBeNull();
});

it('returns null when a line Variant is missing any dimension', function () {
    $shop = esShop(esRate());
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 400, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => null,
    ]), 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order))->toBeNull();
});

it('returns null when one of several lines has an under-specified Variant', function () {
    $shop = esShop(esRate());
    $ok = esVariant(['package_weight_g' => 300, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10]);
    $bad = esVariant(['package_weight_g' => 200, 'package_length_mm' => 10, 'package_width_mm' => null, 'package_height_mm' => 10]);

    $order = esOrder($shop, [['variant' => $ok, 'qty' => 1], ['variant' => $bad, 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($order))->toBeNull();
});

it('returns null when the Order has no lines', function () {
    $shop = esShop(esRate());
    $order = esOrder($shop, []);

    expect(app(ComputeExpectedShipping::class)->handle($order))->toBeNull();
});

// ---------------------------------------------------------------------------
// Fail-loud: a malformed tier is not a missing rate (ADR 0005)
// ---------------------------------------------------------------------------

it('fails loud on a tier entry missing fee', function () {
    $shop = esShop([['up_to_g' => 500]]);
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 400, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(fn () => app(ComputeExpectedShipping::class)->handle($order))
        ->toThrow(InvalidArgumentException::class);
});

it('fails loud on a tier entry with a non-integer up_to_g', function () {
    $shop = esShop([['up_to_g' => '500', 'fee' => 3000]]);
    $order = esOrder($shop, [['variant' => esVariant([
        'package_weight_g' => 400, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(fn () => app(ComputeExpectedShipping::class)->handle($order))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cross-tenant isolation (ADR 0011)
// ---------------------------------------------------------------------------

it('does not reach another tenant\'s ShopSetting / Variant when computing', function () {
    // Tenant A: a fully-specified Order that would compute an expected fee.
    $shopA = esShop(esRate());
    $orderA = esOrder($shopA, [['variant' => esVariant([
        'package_weight_g' => 400, 'package_length_mm' => 10, 'package_width_mm' => 10, 'package_height_mm' => 10,
    ]), 'qty' => 1]]);

    expect(app(ComputeExpectedShipping::class)->handle($orderA)?->satang)->toBe(3000);

    // Switch to Tenant B — A's Order is invisible via the global scope.
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('ExpectedShippingTenantB-'.uniqid());
    app(TenantContext::class)->set($tenantB);

    expect(Order::query()->find($orderA->id))->toBeNull();
});
