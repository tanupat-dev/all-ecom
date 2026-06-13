<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Listings\UpdateListingVariant;
use App\Actions\Promotions\CreatePromotion;
use App\Actions\Promotions\PromotionLineInput;
use App\Actions\Promotions\ResolveEffectivePrice;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\Platform;
use App\Enums\PromotionType;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/**
 * One Listing-Variant on a fresh marketplace Shop with a known List Price, so a
 * test can hang Promotion Lines off a real Listing-Variant. Unique SKU/shop per
 * call (tenant-unique). Mirrors PromotionKernelTest's promoShopListing but
 * returns the Listing-Variant directly (the unit Effective Price resolves on).
 */
function effectivePriceListingVariant(string $listPrice = '199', string $shopName = 'EP'): ListingVariant
{
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle($shopName.' '.Str::random(5), Platform::Shopee, $location);
    $product = app(CreateProduct::class)->handle('สินค้า '.Str::random(5), [
        ['master_sku' => 'EP-'.Str::random(8), 'list_price' => Money::fromBaht($listPrice)],
    ]);

    return app(CreateListing::class)->handle($shop, $product)->variants()->firstOrFail();
}

function resolveEffectivePrice(ListingVariant $lv, ?CarbonImmutable $at = null): Money
{
    return app(ResolveEffectivePrice::class)->handle($lv, $at);
}

/**
 * Self-contained Listing-Variant + base Promotion Line for the isolation
 * harness (it spins up raw Tenants with no seeded default Location).
 */
function effectivePriceCacheRow(): ListingVariant
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
    $shop = app(CreateShop::class)->handle('iso '.Str::random(5), Platform::Shopee, $location);
    $product = app(CreateProduct::class)->handle('iso '.Str::random(5), [
        ['master_sku' => 'ISO-'.Str::random(8), 'list_price' => Money::fromBaht('149')],
    ]);
    $lv = app(CreateListing::class)->handle($shop, $product)->variants()->firstOrFail();

    app(CreatePromotion::class)->handle(PromotionType::Base, 'iso base', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('129')),
    ]);

    return $lv->refresh();
}

// --- Resolution chain ---------------------------------------------------------

it('resolves to the List Price (integer satang) when no Promotion Line applies', function () {
    $lv = effectivePriceListingVariant('199');

    $price = resolveEffectivePrice($lv);

    expect($price)->toBeInstanceOf(Money::class)
        ->and($price->satang)->toBe(19900)
        ->and($lv->refresh()->deal_price)->toBeNull(); // List-Price-only → null cache
});

it('resolves to the base Promotion Line Deal Price when only a base applies', function () {
    $lv = effectivePriceListingVariant('199');

    app(CreatePromotion::class)->handle(PromotionType::Base, 'ลดประจำ', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('159')),
    ]);

    expect(resolveEffectivePrice($lv)->satang)->toBe(15900)
        ->and($lv->refresh()->deal_price?->satang)->toBe(15900);
});

it('prefers an in-window campaign Deal Price over the base', function () {
    $lv = effectivePriceListingVariant('199');
    $now = CarbonImmutable::parse('2026-07-07 12:00:00');

    app(CreatePromotion::class)->handle(PromotionType::Base, 'base', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('159')),
    ]);
    app(CreatePromotion::class)->handle(PromotionType::Campaign, '7.7', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('99')),
    ], $now->subDay(), $now->addDay());

    expect(resolveEffectivePrice($lv, $now)->satang)->toBe(9900);
});

it('falls back to the base when the campaign is out of window', function () {
    $lv = effectivePriceListingVariant('199');
    $start = CarbonImmutable::parse('2026-07-07 00:00:00');

    app(CreatePromotion::class)->handle(PromotionType::Base, 'base', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('159')),
    ]);
    app(CreatePromotion::class)->handle(PromotionType::Campaign, '7.7', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('99')),
    ], $start, $start->addDay());

    expect(resolveEffectivePrice($lv, $start->subSecond())->satang)->toBe(15900) // before
        ->and(resolveEffectivePrice($lv, $start->addDays(2))->satang)->toBe(15900); // after
});

it('treats the window as [start_at, end_at): inclusive start, exclusive end', function () {
    $lv = effectivePriceListingVariant('199');
    $start = CarbonImmutable::parse('2026-07-07 00:00:00');
    $end = $start->addDay();

    app(CreatePromotion::class)->handle(PromotionType::Campaign, '7.7', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('99')),
    ], $start, $end);

    // exactly start_at → active; exactly end_at → no longer active (→ List Price).
    expect(resolveEffectivePrice($lv, $start)->satang)->toBe(9900)
        ->and(resolveEffectivePrice($lv, $end)->satang)->toBe(19900);
});

// --- One-active-line invariant (no overlapping campaigns) ---------------------

it('fails loud on a second campaign whose window overlaps an existing campaign on the same Listing-Variant', function () {
    $lv = effectivePriceListingVariant('199');
    $start = CarbonImmutable::parse('2026-07-07 00:00:00');

    app(CreatePromotion::class)->handle(PromotionType::Campaign, '7.7', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('99')),
    ], $start, $start->addDays(2));

    app(CreatePromotion::class)->handle(PromotionType::Campaign, '8.8', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('89')),
    ], $start->addDay(), $start->addDays(3));
})->throws(InvalidArgumentException::class, 'overlapping campaigns');

it('allows a back-to-back campaign that only touches the boundary (exclusive end, no overlap)', function () {
    $lv = effectivePriceListingVariant('199');
    $start = CarbonImmutable::parse('2026-07-07 00:00:00');
    $mid = $start->addDay();

    app(CreatePromotion::class)->handle(PromotionType::Campaign, 'a', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('99')),
    ], $start, $mid);

    $second = app(CreatePromotion::class)->handle(PromotionType::Campaign, 'b', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('89')),
    ], $mid, $mid->addDay());

    expect($second->lines)->toHaveCount(1);
});

it('allows an overlapping campaign on a DIFFERENT Listing-Variant', function () {
    $lvA = effectivePriceListingVariant('199', 'A');
    $lvB = effectivePriceListingVariant('199', 'B');
    $start = CarbonImmutable::parse('2026-07-07 00:00:00');

    app(CreatePromotion::class)->handle(PromotionType::Campaign, 'a', [
        PromotionLineInput::dealPrice($lvA, Money::fromBaht('99')),
    ], $start, $start->addDays(2));

    $second = app(CreatePromotion::class)->handle(PromotionType::Campaign, 'b', [
        PromotionLineInput::dealPrice($lvB, Money::fromBaht('89')),
    ], $start, $start->addDays(2)); // same window, other Listing-Variant

    expect($second->lines)->toHaveCount(1);
});

// --- Deal Price cache write-through -------------------------------------------

it('writes the cache to the active Deal Price on creating a Promotion, and null when only List Price applies', function () {
    $lv = effectivePriceListingVariant('199');
    expect($lv->refresh()->deal_price)->toBeNull(); // no promotion yet

    app(CreatePromotion::class)->handle(PromotionType::Base, 'base', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('159')),
    ]);

    $cache = $lv->refresh()->deal_price;
    expect($cache)->toBeInstanceOf(Money::class)
        ->and($cache?->satang)->toBe(15900)
        ->and($cache?->satang)->toBe(resolveEffectivePrice($lv)->satang);
});

it('keeps the cache equal to ResolveEffectivePrice after a Promotion Line is edited', function () {
    $lv = effectivePriceListingVariant('199');
    $promotion = app(CreatePromotion::class)->handle(PromotionType::Base, 'base', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('159')),
    ]);
    expect($lv->refresh()->deal_price?->satang)->toBe(15900);

    $promotion->lines()->firstOrFail()->update(['deal_price' => Money::fromBaht('149')]);

    expect($lv->refresh()->deal_price?->satang)->toBe(14900)
        ->and($lv->refresh()->deal_price?->satang)->toBe(resolveEffectivePrice($lv)->satang);
});

it('clears the cache to null when the only Promotion Line is deleted', function () {
    $lv = effectivePriceListingVariant('199');
    $promotion = app(CreatePromotion::class)->handle(PromotionType::Base, 'base', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('159')),
    ]);
    expect($lv->refresh()->deal_price?->satang)->toBe(15900);

    $promotion->lines()->firstOrFail()->delete();

    expect($lv->refresh()->deal_price)->toBeNull()
        ->and(resolveEffectivePrice($lv)->satang)->toBe(19900);
});

// --- Time-boundary cache refresh (scheduled command) --------------------------

it('promotions:refresh-cache recomputes a cache that went stale when a campaign window closed with no edit', function () {
    $lv = effectivePriceListingVariant('199');
    $start = CarbonImmutable::parse('2026-07-07 00:00:00');

    Carbon::setTestNow($start);
    CarbonImmutable::setTestNow($start);
    app(CreatePromotion::class)->handle(PromotionType::Campaign, '7.7', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('99')),
    ], $start, $start->addDay());
    expect($lv->refresh()->deal_price?->satang)->toBe(9900); // cache set in-window

    // Window closes with no write — the cache is now stale (observer can't fire).
    $after = $start->addDays(2);
    Carbon::setTestNow($after);
    CarbonImmutable::setTestNow($after);
    expect($lv->refresh()->deal_price?->satang)->toBe(9900); // still stale

    Artisan::call('promotions:refresh-cache');

    expect($lv->refresh()->deal_price)->toBeNull() // refreshed to List-Price-only
        ->and(resolveEffectivePrice($lv)->satang)->toBe(19900);
});

// --- Direct-edit path removed (ADR 0021) --------------------------------------

it('no longer lets UpdateListingVariant change the Deal Price cache (it only remaps the Platform SKU)', function () {
    $lv = effectivePriceListingVariant('199');
    app(CreatePromotion::class)->handle(PromotionType::Base, 'base', [
        PromotionLineInput::dealPrice($lv, Money::fromBaht('159')),
    ]);
    expect($lv->refresh()->deal_price?->satang)->toBe(15900);

    app(UpdateListingVariant::class)->handle($lv, 'NEW-SKU-1');

    $lv->refresh();
    expect($lv->platform_sku)->toBe('NEW-SKU-1')
        ->and($lv->deal_price?->satang)->toBe(15900); // cache untouched by the SKU edit
});

// --- Tenancy ------------------------------------------------------------------

it('does not resolve another tenant\'s Promotion Line into the Effective Price', function () {
    $lvA = effectivePriceListingVariant('199', 'tenant-A-shop');
    app(CreatePromotion::class)->handle(PromotionType::Base, 'A base', [
        PromotionLineInput::dealPrice($lvA, Money::fromBaht('99')),
    ]);

    // A second tenant with its own Listing-Variant and no promotion.
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);

    $lvB = effectivePriceListingVariant('250', 'tenant-B-shop');

    // B resolves its own List Price — never A's deal_price, and its cache is null.
    expect(resolveEffectivePrice($lvB)->satang)->toBe(25000)
        ->and($lvB->refresh()->deal_price)->toBeNull();
});

it('passes the cross-tenant isolation harness (deal price cache row)', function () {
    assertTenantIsolation(fn (): ListingVariant => effectivePriceCacheRow());
});
