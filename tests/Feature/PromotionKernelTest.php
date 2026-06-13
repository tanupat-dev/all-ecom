<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Promotions\CreatePromotion;
use App\Actions\Promotions\PromotionLineInput;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\Platform;
use App\Enums\PromotionType;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Promotion;
use App\Models\PromotionLine;
use App\Support\Money;
use App\Support\PercentOff;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * A Shop + a one-Variant Listing on it, so a test can attach a Promotion Line
 * to a real Listing-Variant. Unique Master SKU per call (tenant-unique).
 */
function promoShopListing(string $shopName, string $listPrice = '199', Platform $platform = Platform::Shopee): Listing
{
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle($shopName, $platform, $location);
    $product = app(CreateProduct::class)->handle('สินค้า '.$shopName, [
        ['master_sku' => 'SKU-'.Str::random(8), 'list_price' => Money::fromBaht($listPrice)],
    ]);

    return app(CreateListing::class)->handle($shop, $product);
}

/**
 * Builds exactly one promotion_lines row (and its parent Promotion) for the
 * current tenant — the cross-tenant isolation harness needs a self-contained
 * row builder (it creates raw Tenants without CreateTenant's seeded Location).
 */
function promotionLineRow(): PromotionLine
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
    $shop = app(CreateShop::class)->handle('ร้าน harness', Platform::Shopee, $location);
    $product = app(CreateProduct::class)->handle('แก้วน้ำ', [
        ['master_sku' => 'CUP-1', 'list_price' => Money::fromBaht('59')],
    ]);
    $listing = app(CreateListing::class)->handle($shop, $product);

    $promotion = app(CreatePromotion::class)->handle(
        PromotionType::Base,
        'Harness base',
        [PromotionLineInput::dealPrice($listing->variants()->firstOrFail(), Money::fromBaht('49'))],
    );

    return $promotion->lines()->firstOrFail();
}

it('creates a base Promotion with its lines, Deal Price stored as integer satang', function () {
    $variant = promoShopListing('ร้าน A')->variants()->firstOrFail();

    $promotion = app(CreatePromotion::class)->handle(
        PromotionType::Base,
        'ลดประจำ',
        [PromotionLineInput::dealPrice($variant, Money::fromBaht('159.50'))],
    );

    expect($promotion->type)->toBe(PromotionType::Base)
        ->and($promotion->start_at)->toBeNull()
        ->and($promotion->end_at)->toBeNull()
        ->and($promotion->lines)->toHaveCount(1);

    $line = $promotion->lines->firstOrFail();

    // The cast's is_int guard (MoneyCast) only yields a Money when the column
    // holds an integer satang value — so this also proves no-float (ADR 0015).
    expect($line->deal_price)->toBeInstanceOf(Money::class)
        ->and($line->deal_price->satang)->toBe(15950);
});

it('converts a "% off" entry to the right Deal Price satang, rounding so the discount is never deeper than asked', function () {
    // ฿99.99 = 9999 satang; 10% off = 8999.1 satang → Deal Price rounds UP to
    // 9000 satang (฿90.00) so the realised discount (฿9.99) is never deeper
    // than the ฿9.999 asked (CONTEXT.md: Deal Price; ADR 0021).
    $variant = promoShopListing('ร้าน B', listPrice: '99.99')->variants()->firstOrFail();

    $promotion = app(CreatePromotion::class)->handle(
        PromotionType::Base,
        '10% off',
        [PromotionLineInput::percentOff($variant, PercentOff::fromPercent('10'))],
    );

    expect($promotion->lines->firstOrFail()->deal_price->satang)->toBe(9000);
});

it('stores Deal Price in an integer (bigint) column, never a float/decimal type (ADR 0015)', function () {
    $variant = promoShopListing('ร้าน G')->variants()->firstOrFail();

    $promotion = app(CreatePromotion::class)->handle(
        PromotionType::Base,
        'ลด',
        [PromotionLineInput::dealPrice($variant, Money::fromBaht('123.45'))],
    );

    $column = (array) DB::selectOne(
        "select data_type from information_schema.columns where table_name = 'promotion_lines' and column_name = 'deal_price'"
    );

    expect($column['data_type'])->toBe('bigint')
        ->and(PromotionLine::findOrFail($promotion->lines->firstOrFail()->id)->deal_price->satang)->toBe(12345);
});

it('fails loud on a second active base Promotion touching a Shop that already has one', function () {
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle('ร้านเดียว', Platform::Shopee, $location);

    $first = app(CreateListing::class)->handle($shop, app(CreateProduct::class)->handle('A', [
        ['master_sku' => 'A-1', 'list_price' => Money::fromBaht('100')],
    ]));
    $second = app(CreateListing::class)->handle($shop, app(CreateProduct::class)->handle('B', [
        ['master_sku' => 'B-1', 'list_price' => Money::fromBaht('100')],
    ]));

    app(CreatePromotion::class)->handle(PromotionType::Base, 'base1', [
        PromotionLineInput::dealPrice($first->variants()->firstOrFail(), Money::fromBaht('90')),
    ]);

    app(CreatePromotion::class)->handle(PromotionType::Base, 'base2', [
        PromotionLineInput::dealPrice($second->variants()->firstOrFail(), Money::fromBaht('90')),
    ]);
})->throws(InvalidArgumentException::class, 'base Promotion');

it('lets one base Promotion span multiple Shops via its lines', function () {
    $shopee = promoShopListing('ร้าน Shopee', platform: Platform::Shopee)->variants()->firstOrFail();
    $lazada = promoShopListing('ร้าน Lazada', platform: Platform::Lazada)->variants()->firstOrFail();

    $promotion = app(CreatePromotion::class)->handle(PromotionType::Base, 'ลดข้ามร้าน', [
        PromotionLineInput::dealPrice($shopee, Money::fromBaht('90')),
        PromotionLineInput::dealPrice($lazada, Money::fromBaht('80')),
    ]);

    expect($promotion->lines)->toHaveCount(2);
});

it('rejects a campaign with no time window', function () {
    $variant = promoShopListing('ร้าน C')->variants()->firstOrFail();

    app(CreatePromotion::class)->handle(
        PromotionType::Campaign,
        '7.7',
        [PromotionLineInput::dealPrice($variant, Money::fromBaht('80'))],
    );
})->throws(InvalidArgumentException::class, 'both start_at and end_at');

it('rejects a campaign whose start_at is not before end_at', function () {
    $variant = promoShopListing('ร้าน C2')->variants()->firstOrFail();

    app(CreatePromotion::class)->handle(
        PromotionType::Campaign,
        '7.7',
        [PromotionLineInput::dealPrice($variant, Money::fromBaht('80'))],
        now()->addDay(),
        now(),
    );
})->throws(InvalidArgumentException::class, 'start_at < end_at');

it('rejects a base Promotion that carries a time window', function () {
    $variant = promoShopListing('ร้าน D')->variants()->firstOrFail();

    app(CreatePromotion::class)->handle(
        PromotionType::Base,
        'ลด',
        [PromotionLineInput::dealPrice($variant, Money::fromBaht('80'))],
        now(),
        now()->addDay(),
    );
})->throws(InvalidArgumentException::class, 'must not carry a time window');

it('creates a campaign with a valid window and persists start_at/end_at', function () {
    $variant = promoShopListing('ร้าน E')->variants()->firstOrFail();

    $promotion = app(CreatePromotion::class)->handle(
        PromotionType::Campaign,
        '6.6',
        [PromotionLineInput::dealPrice($variant, Money::fromBaht('80'))],
        now(),
        now()->addWeek(),
    );

    expect($promotion->type)->toBe(PromotionType::Campaign)
        ->and($promotion->start_at)->not->toBeNull()
        ->and($promotion->end_at)->not->toBeNull();
});

it('allows a campaign on a Shop that already has a base Promotion — campaigns are not limited here (#74 owns overlap)', function () {
    $variant = promoShopListing('ร้าน F')->variants()->firstOrFail();

    app(CreatePromotion::class)->handle(PromotionType::Base, 'base', [
        PromotionLineInput::dealPrice($variant, Money::fromBaht('90')),
    ]);

    $campaign = app(CreatePromotion::class)->handle(
        PromotionType::Campaign,
        'camp',
        [PromotionLineInput::dealPrice($variant, Money::fromBaht('70'))],
        now(),
        now()->addDay(),
    );

    expect($campaign->lines)->toHaveCount(1);
});

it('rejects two lines for the same Listing-Variant in one Promotion', function () {
    $variant = promoShopListing('ร้าน I')->variants()->firstOrFail();

    app(CreatePromotion::class)->handle(PromotionType::Base, 'ซ้ำ', [
        PromotionLineInput::dealPrice($variant, Money::fromBaht('90')),
        PromotionLineInput::dealPrice($variant, Money::fromBaht('80')),
    ]);
})->throws(InvalidArgumentException::class, 'at most one line per Listing-Variant');

it('lets an Admin through the Promotion screens and blocks a Cashier', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);
    get(PromotionResource::getUrl('index'))->assertOk();

    [, $cashier] = tenantWithUser('Cashier');
    actingAs($cashier);
    get(PromotionResource::getUrl('index'))->assertForbidden();
});

it('passes the cross-tenant isolation harness (promotion lines)', function () {
    assertTenantIsolation(fn (): PromotionLine => promotionLineRow());
});

it('passes the cross-tenant isolation harness (promotions)', function () {
    assertTenantIsolation(fn (): Promotion => promotionLineRow()->promotion()->firstOrFail());
});
