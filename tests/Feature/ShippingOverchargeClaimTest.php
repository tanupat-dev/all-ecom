<?php

use App\Actions\Accounting\UpsertAccountingCycle;
use App\Actions\Catalog\CreateProduct;
use App\Actions\Claims\FlagShippingOverchargeClaim;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\AccountingLineCategory;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\AccountingEntryLine;
use App\Models\Claim;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('ShippingOverchargeTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Helpers — a marketplace Shop with a rate, an Order with a weighed Variant.
// Rate: ≤1000g → ฿30 (3000 satang). The standard Variant weighs 500g (dims
// tiny so volumetric loses) → chargeable 500g → expected 3000 satang.
// ---------------------------------------------------------------------------

/**
 * @param  array<array-key, mixed>|null  $rate
 */
function socShop(?array $rate = [['up_to_g' => 1000, 'fee' => 3000]]): Shop
{
    $shop = app(CreateShop::class)->handle('SOC Shop '.uniqid(), Platform::Shopee, Location::query()->firstOrFail());
    $shop->settings()->firstOrFail()->update(['expected_shipping_rate' => $rate]);

    return $shop->refresh();
}

function socVariant(int $weightG = 500): Variant
{
    $product = app(CreateProduct::class)->handle('SOC Product '.uniqid(), [[
        'master_sku' => 'SOC-'.uniqid(),
        'list_price' => Money::fromBaht('100'),
        'package_weight_g' => $weightG,
        'package_length_mm' => 10,
        'package_width_mm' => 10,
        'package_height_mm' => 10,
    ]]);

    return $product->variants->firstOrFail();
}

function socOrder(Shop $shop): Order
{
    $order = Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => 'SOC-ORD-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);

    $order->lines()->create([
        'variant_id' => socVariant()->id,
        'qty' => 1,
        'unit_price' => Money::fromBaht('100'),
        'line_total' => Money::fromBaht('100'),
    ]);

    return $order->refresh();
}

/** Append a signed-negative shipping_seller_paid line of $baht. */
function socShippingLine(Order $order, string $baht, string $cycle = 'CYCLE-1'): void
{
    AccountingEntryLine::query()->create([
        'order_id' => $order->id,
        'statement_cycle' => $cycle,
        'source_field' => 'ค่าจัดส่งที่ผู้ขายจ่าย',
        'category' => AccountingLineCategory::ShippingSellerPaid,
        'amount' => Money::fromBaht($baht),
    ]);
}

// ---------------------------------------------------------------------------
// FlagShippingOverchargeClaim — direct invocation
// ---------------------------------------------------------------------------

describe('FlagShippingOverchargeClaim — direct invocation', function () {
    it('flags an eligible shipping_overcharge Claim when paid − expected exceeds ฿5', function () {
        $shop = socShop();
        $order = socOrder($shop);
        socShippingLine($order, '-40'); // paid 4000, expected 3000, diff 1000 > 500

        $claim = app(FlagShippingOverchargeClaim::class)->handle($order);

        expect($claim)->not->toBeNull()
            ->and($claim?->claim_type)->toBe(ClaimType::ShippingOvercharge)
            ->and($claim?->status)->toBe(ClaimStatus::Eligible)
            ->and($claim?->ref_order_id)->toBe($order->id)
            ->and($claim?->ref_return_id)->toBeNull()
            // SeedDefaultEvidence ran via CreateClaim.
            ->and($claim?->evidenceItems()->count())->toBeGreaterThan(0);
    });

    it('does not flag when the overcharge is within tolerance', function () {
        $shop = socShop();
        $order = socOrder($shop);
        socShippingLine($order, '-34'); // paid 3400, expected 3000, diff 400 ≤ 500

        expect(app(FlagShippingOverchargeClaim::class)->handle($order))->toBeNull();
    });

    it('does not flag exactly at the ฿5 tolerance boundary', function () {
        $shop = socShop();
        $order = socOrder($shop);
        socShippingLine($order, '-35'); // diff exactly 500, not > 500

        expect(app(FlagShippingOverchargeClaim::class)->handle($order))->toBeNull();
    });

    it('does not flag when the expected fee cannot be computed (no rate)', function () {
        $shop = socShop(null);
        $order = socOrder($shop);
        socShippingLine($order, '-40');

        expect(app(FlagShippingOverchargeClaim::class)->handle($order))->toBeNull();
    });

    it('sums multiple shipping_seller_paid lines and takes the absolute value', function () {
        $shop = socShop();
        $order = socOrder($shop);
        socShippingLine($order, '-20', 'CYCLE-1'); // −2000
        socShippingLine($order, '-25', 'CYCLE-2'); // −2500  → |Σ| = 4500

        $claim = app(FlagShippingOverchargeClaim::class)->handle($order);

        // 4500 − 3000 = 1500 > 500 → flag, exactly one Claim.
        expect($claim)->not->toBeNull();
        expect(Claim::query()->where('ref_order_id', $order->id)->where('claim_type', ClaimType::ShippingOvercharge)->count())->toBe(1);
    });

    it('is idempotent — calling twice yields exactly one Claim', function () {
        $shop = socShop();
        $order = socOrder($shop);
        socShippingLine($order, '-40');

        $first = app(FlagShippingOverchargeClaim::class)->handle($order);
        $second = app(FlagShippingOverchargeClaim::class)->handle($order);

        expect($first?->id)->toBe($second?->id);
        expect(Claim::query()->where('ref_order_id', $order->id)->where('claim_type', ClaimType::ShippingOvercharge)->count())->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// Integration via UpsertAccountingCycle — same transaction, idempotent
// ---------------------------------------------------------------------------

describe('UpsertAccountingCycle auto-flag integration', function () {
    it('importing an over-charged cycle creates exactly one shipping_overcharge Claim', function () {
        $shop = socShop();
        $order = socOrder($shop);

        app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', [
            ['source_field' => 'ยอดขายสินค้า', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('100')],
            ['source_field' => 'ค่าจัดส่งที่ผู้ขายจ่าย', 'category' => AccountingLineCategory::ShippingSellerPaid, 'amount' => Money::fromBaht('-40')],
        ]);

        $claims = Claim::query()
            ->where('ref_order_id', $order->id)
            ->where('claim_type', ClaimType::ShippingOvercharge)
            ->get();

        expect($claims)->toHaveCount(1)
            ->and($claims->first()?->status)->toBe(ClaimStatus::Eligible)
            ->and($claims->first()?->ref_return_id)->toBeNull();
    });

    it('re-importing the same cycle does not duplicate the Claim', function () {
        $shop = socShop();
        $order = socOrder($shop);

        $lines = [
            ['source_field' => 'ยอดขายสินค้า', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('100')],
            ['source_field' => 'ค่าจัดส่งที่ผู้ขายจ่าย', 'category' => AccountingLineCategory::ShippingSellerPaid, 'amount' => Money::fromBaht('-40')],
        ];

        app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', $lines);
        app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', $lines);
        app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', $lines);

        expect(Claim::query()->where('ref_order_id', $order->id)->where('claim_type', ClaimType::ShippingOvercharge)->count())->toBe(1);
    });

    it('importing a within-tolerance cycle creates no Claim', function () {
        $shop = socShop();
        $order = socOrder($shop);

        app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', [
            ['source_field' => 'ยอดขายสินค้า', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('100')],
            ['source_field' => 'ค่าจัดส่งที่ผู้ขายจ่าย', 'category' => AccountingLineCategory::ShippingSellerPaid, 'amount' => Money::fromBaht('-34')],
        ]);

        expect(Claim::query()->where('ref_order_id', $order->id)->where('claim_type', ClaimType::ShippingOvercharge)->count())->toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Cross-tenant isolation (ADR 0011)
// ---------------------------------------------------------------------------

it('passes the cross-tenant isolation harness for auto-flagged shipping_overcharge Claims', function () {
    assertTenantIsolation(function (): Claim {
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle('SOC xten '.uniqid(), Platform::Shopee, $location);
        $shop->settings()->firstOrFail()->update(['expected_shipping_rate' => [['up_to_g' => 1000, 'fee' => 3000]]]);
        $shop->refresh();

        $order = socOrder($shop);
        socShippingLine($order, '-40');

        $claim = app(FlagShippingOverchargeClaim::class)->handle($order);
        assert($claim !== null);

        return $claim;
    });
});

it('cross-tenant: Tenant B cannot see Tenant A\'s auto-flagged shipping_overcharge Claim', function () {
    $shopA = socShop();
    $orderA = socOrder($shopA);
    socShippingLine($orderA, '-40');
    $claimA = app(FlagShippingOverchargeClaim::class)->handle($orderA);
    assert($claimA !== null);

    // Switch to Tenant B.
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('ShippingOverchargeTenantB-'.uniqid());
    app(TenantContext::class)->set($tenantB);

    expect(Claim::query()->where('claim_type', ClaimType::ShippingOvercharge)->pluck('id'))
        ->not->toContain($claimA->id);
});
