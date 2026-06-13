<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\DefineBundle;
use App\Actions\Catalog\SetCostPrice;
use App\Actions\Listings\CreateListing;
use App\Actions\Pricing\ComputeMargin;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\AccountingLineCategory;
use App\Enums\Platform;
use App\Filament\Pages\MarginCalculator;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Models\PlatformFeeProfile;
use App\Models\Shop;
use App\Models\User;
use App\Models\Variant;
use App\Support\MarginTarget;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    Carbon::setTestNow();
    app(TenantContext::class)->forget();
});

// ─── helpers ────────────────────────────────────────────────────────────────

function marginShop(string $name = 'Shopee'): Shop
{
    return app(CreateShop::class)->handle($name, Platform::Shopee, Location::query()->firstOrFail());
}

/** A Variant priced + (optionally) costed since long ago, with one component-free cost. */
function marginVariant(string $sku, string $priceBaht, ?string $costBaht): Variant
{
    $variant = app(CreateProduct::class)
        ->handle('สินค้า '.uniqid(), [['master_sku' => $sku, 'list_price' => Money::fromBaht($priceBaht)]])
        ->variants->firstOrFail();

    if ($costBaht !== null) {
        app(SetCostPrice::class)->handle($variant, Money::fromBaht($costBaht), Carbon::parse('2020-01-01'));
    }

    return $variant;
}

/** Place $variant on $shop and return its Listing-Variant row. */
function marginListingVariant(Shop $shop, Variant $variant): ListingVariant
{
    $listing = app(CreateListing::class)->handle($shop, $variant->product()->firstOrFail());

    return $listing->variants()->where('variant_id', $variant->id)->firstOrFail();
}

function marginFee(Shop $shop, AccountingLineCategory $category, int $rateBps, int $fixedSatang = 0): void
{
    PlatformFeeProfile::query()->create([
        'shop_id' => $shop->id,
        'category' => $category,
        'rate_bps' => $rateBps,
        'fixed_satang' => $fixedSatang,
    ]);
}

// ─── forward: target % profit → recommended Effective Price ──────────────────

it('recommends the smallest Effective Price whose realised net ≥ cost + target %, to the satang', function () {
    $shop = marginShop();
    $lv = marginListingVariant($shop, marginVariant('MC-1', '200', '100')); // cost ฿100 = 10_000 satang
    marginFee($shop, AccountingLineCategory::Commission, 1000); // 10%

    // required_net = 10_000 + 30% of 10_000 = 13_000 satang.
    $price = app(ComputeMargin::class)->recommendedPrice($lv, MarginTarget::percentOfCost(3000));

    // Analytic ceil = 14_445, but half-up fee rounding gives the seller a free
    // satang at 14_444 (fee 1_444 → net 13_000), so the SMALLEST valid price is
    // 14_444, not 14_445 — the ±1-satang adjustment must catch this.
    expect($price->satang)->toBe(14_444);

    // Pin: fed back through the SAME forward fee math, realised net ≥ required.
    $realisedNet = app(ComputeMargin::class)->impliedProfit($lv, $price)->satang + 10_000;
    expect($realisedNet)->toBeGreaterThanOrEqual(13_000)->toBe(13_000);
});

it('recommends a price covering a commission % AND a fixed per-order fee', function () {
    $shop = marginShop();
    $lv = marginListingVariant($shop, marginVariant('MC-2', '200', '100')); // cost 10_000
    marginFee($shop, AccountingLineCategory::Commission, 1000);        // 10%
    marginFee($shop, AccountingLineCategory::PaymentFee, 0, 300);      // flat ฿3

    $price = app(ComputeMargin::class)->recommendedPrice($lv, MarginTarget::percentOfCost(3000));

    // required 13_000; smallest price = 14_778 (commission 1_478 + flat 300 =
    // 1_778 fees → net 13_000; at 14_777 net is 12_999).
    expect($price->satang)->toBe(14_778);
    expect(app(ComputeMargin::class)->impliedProfit($lv, $price)->satang)->toBe(3_000);
});

// ─── forward: fixed-THB target ───────────────────────────────────────────────

it('recommends a price for a fixed-THB profit target', function () {
    $shop = marginShop();
    $lv = marginListingVariant($shop, marginVariant('MC-3', '200', '100')); // cost 10_000
    marginFee($shop, AccountingLineCategory::Commission, 1000); // 10%

    // required_net = 10_000 + 5_000 (฿50) = 15_000 satang.
    $price = app(ComputeMargin::class)->recommendedPrice($lv, MarginTarget::fixed(Money::fromBaht('50')));

    expect($price->satang)->toBe(16_667); // fee 1_667 → net 15_000; at 16_666 net 14_999
    expect(app(ComputeMargin::class)->impliedProfit($lv, $price)->satang)->toBe(5_000);
});

// ─── symmetric: Effective Price → implied profit ─────────────────────────────

it('returns the implied profit for a given Effective Price, round-tripping the forward case', function () {
    $shop = marginShop();
    $lv = marginListingVariant($shop, marginVariant('MC-4', '200', '100')); // cost 10_000
    marginFee($shop, AccountingLineCategory::Commission, 1000); // 10%

    // 14_444 was the forward recommendation for a 3_000 target → profit 3_000.
    $profit = app(ComputeMargin::class)->impliedProfit($lv, Money::fromSatang(14_444));
    expect($profit->satang)->toBe(3_000);
});

it('returns a signed (negative) implied profit when the price does not cover cost + fees', function () {
    $shop = marginShop();
    $lv = marginListingVariant($shop, marginVariant('MC-5', '200', '100')); // cost 10_000
    marginFee($shop, AccountingLineCategory::Commission, 1000); // 10%

    // Price = cost = 10_000; fee 1_000 → net 9_000; profit = 9_000 − 10_000.
    $profit = app(ComputeMargin::class)->impliedProfit($lv, Money::fromSatang(10_000));
    expect($profit->satang)->toBe(-1_000);
});

// ─── bundle: cost = Σ component costAt ───────────────────────────────────────

it('uses the bundle cost (Σ component costAt) for the recommendation', function () {
    $shop = marginShop();

    $a = marginVariant('MC-CMP-A', '60', '40'); // component cost ฿40
    $b = marginVariant('MC-CMP-B', '90', '60'); // component cost ฿60
    $bundle = marginVariant('MC-BUNDLE', '300', null); // bundle has NO own cost row
    app(DefineBundle::class)->handle($bundle, [[$a, 1], [$b, 1]]);

    $lv = marginListingVariant($shop, $bundle);
    marginFee($shop, AccountingLineCategory::Commission, 1000); // 10%

    // bundle cost = 40 + 60 = ฿100 = 10_000 satang → identical to the MC-1 case.
    $price = app(ComputeMargin::class)->recommendedPrice($lv, MarginTarget::percentOfCost(3000));
    expect($price->satang)->toBe(14_444);
});

// ─── fail-loud: no Cost Price at now ─────────────────────────────────────────

it('fails loud when the Variant has no Cost Price at now (margin undefined)', function () {
    $shop = marginShop();
    $lv = marginListingVariant($shop, marginVariant('MC-NOCOST', '200', null)); // no cost
    marginFee($shop, AccountingLineCategory::Commission, 1000);

    expect(fn () => app(ComputeMargin::class)->recommendedPrice($lv, MarginTarget::percentOfCost(3000)))
        ->toThrow(LogicException::class, 'MC-NOCOST');

    expect(fn () => app(ComputeMargin::class)->impliedProfit($lv, Money::fromSatang(20_000)))
        ->toThrow(LogicException::class, 'MC-NOCOST');
});

// ─── satang only (ADR 0015) ──────────────────────────────────────────────────

it('produces only whole-satang integer Money (never a float)', function () {
    $shop = marginShop();
    $lv = marginListingVariant($shop, marginVariant('MC-INT', '200', '100'));
    marginFee($shop, AccountingLineCategory::Commission, 321); // 3.21% — non-round rate

    $price = app(ComputeMargin::class)->recommendedPrice($lv, MarginTarget::percentOfCost(3000));
    expect($price)->toBeInstanceOf(Money::class)
        ->and($price->satang)->toBeInt();
});

// ─── cross-tenant: only the current tenant's Fee Profile / costs ─────────────

it('reads only the current tenant\'s Fee Profile and cost', function () {
    // Tenant A: cost ฿100, commission 10% → recommendation 14_444 for a 30% target.
    $shopA = marginShop('Shopee-A');
    $lvA = marginListingVariant($shopA, marginVariant('MC-T-A', '200', '100'));
    marginFee($shopA, AccountingLineCategory::Commission, 1000);

    // Tenant B: a wildly different fee profile that must never affect A.
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $shopB = app(CreateShop::class)->handle('Shopee-B', Platform::Shopee, Location::query()->firstOrFail());
    marginFee($shopB, AccountingLineCategory::Commission, 5000); // 50%

    // Back to A: the recommendation still uses only A's 10% commission + A's cost.
    app(TenantContext::class)->set($lvA->tenant()->firstOrFail());
    $price = app(ComputeMargin::class)->recommendedPrice($lvA, MarginTarget::percentOfCost(3000));
    expect($price->satang)->toBe(14_444);
});

// ─── RBAC: cost.view gates the page ──────────────────────────────────────────

it('forbids the Margin Calculator page for a user without cost.view', function () {
    [$tenant] = tenantWithUser('Admin');

    $blind = Role::findOrCreate('ไม่เห็นต้นทุน', 'web');
    $blind->syncPermissions(['product.view']); // no cost.view
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($blind);

    actingAs($user);

    get(MarginCalculator::getUrl())->assertForbidden();
});

it('lets an admin with cost.view open the Margin Calculator and see a recommendation in baht', function () {
    [$tenant, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shop = marginShop();
    $lv = marginListingVariant($shop, marginVariant('MC-UI', '200', '100'));
    marginFee($shop, AccountingLineCategory::Commission, 1000);

    get(MarginCalculator::getUrl())->assertOk();

    Livewire::test(MarginCalculator::class)
        ->set('listingVariantId', $lv->id)
        ->set('direction', 'forward')
        ->set('targetType', 'percent')
        ->set('targetValue', '30')
        ->assertSee('144.44'); // recommended ฿144.44
});
