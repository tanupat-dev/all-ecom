<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Listings\ResolvePlatformSku;
use App\Actions\Listings\UpdateListingVariant;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ListingStatus;
use App\Enums\Platform;
use App\Filament\Resources\Listings\ListingResource;
use App\Listings\PlatformSkuConflictException;
use App\Listings\UnresolvedPlatformSkuException;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shop;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

function marketplaceShop(string $name = 'ร้าน Shopee', Platform $platform = Platform::Shopee): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle($name, $platform, $location);
}

function listedProduct(): Product
{
    return app(CreateProduct::class)->handle('เสื้อยืด', [
        ['master_sku' => 'TS-RED-M', 'name' => 'แดง / M', 'list_price' => Money::fromBaht('199')],
        ['master_sku' => 'TS-RED-L', 'name' => 'แดง / L', 'list_price' => Money::fromBaht('199')],
    ]);
}

it('refuses a Listing on a pos Shop — no projection layer where there is no projection to manage', function () {
    $pos = marketplaceShop('หน้าร้าน', Platform::Pos);

    app(CreateListing::class)->handle($pos, listedProduct());
})->throws(InvalidArgumentException::class, 'marketplace');

it('resolves (Shop, Platform SKU) to exactly one Variant', function () {
    $shop = marketplaceShop();
    $product = listedProduct();
    app(CreateListing::class)->handle($shop, $product);

    $variant = app(ResolvePlatformSku::class)->handle($shop, 'TS-RED-L');

    expect($variant->master_sku)->toBe('TS-RED-L');
});

it('fails loud on a Platform SKU with no mapping — never a dropped or orphaned line', function () {
    $shop = marketplaceShop();
    app(CreateListing::class)->handle($shop, listedProduct());

    app(ResolvePlatformSku::class)->handle($shop, 'UNKNOWN-SKU');
})->throws(UnresolvedPlatformSkuException::class, 'UNKNOWN-SKU');

it('re-maps a Platform SKU override and resolves through it — many SKUs to one Variant is fine', function () {
    $shop = marketplaceShop();
    $listing = app(CreateListing::class)->handle($shop, listedProduct());
    $mapping = $listing->variants()->where('platform_sku', 'TS-RED-M')->firstOrFail();

    app(UpdateListingVariant::class)->handle($mapping, 'OLD-SHOPEE-CODE-1', null);

    expect(app(ResolvePlatformSku::class)->handle($shop, 'OLD-SHOPEE-CODE-1')->master_sku)->toBe('TS-RED-M');
});

it('lets the same (Shop, Platform SKU) appear on several Listings when it points at the same Variant', function () {
    $shop = marketplaceShop();
    $product = listedProduct();
    app(CreateListing::class)->handle($shop, $product);

    // The seller relisted the same Product on the same Shop — the repeated
    // SKUs reinforce the same map entries (CONTEXT.md: Platform SKU).
    app(CreateListing::class)->handle($shop, $product);

    expect(app(ResolvePlatformSku::class)->handle($shop, 'TS-RED-M')->master_sku)->toBe('TS-RED-M');
});

it('fails loud when a (Shop, Platform SKU) would point at two different Variants', function () {
    $shop = marketplaceShop();
    $listing = app(CreateListing::class)->handle($shop, listedProduct());
    $mapping = $listing->variants()->where('platform_sku', 'TS-RED-M')->firstOrFail();

    // TS-RED-L already maps to the L Variant on this Shop; pointing the M
    // Variant's row at the same SKU breaks the resolution function.
    app(UpdateListingVariant::class)->handle($mapping, 'TS-RED-L', null);
})->throws(PlatformSkuConflictException::class, 'TS-RED-L');

it('scopes the resolution map per Shop — the same SKU may point at different Variants on different Shops', function () {
    $shopee = marketplaceShop('shopee1');
    $lazada = marketplaceShop('lazada1', Platform::Lazada);
    $product = listedProduct();
    $other = app(CreateProduct::class)->handle('แก้วน้ำ', [
        ['master_sku' => 'CUP-1', 'list_price' => Money::fromBaht('59')],
    ]);

    $onShopee = app(CreateListing::class)->handle($shopee, $product);
    $onLazada = app(CreateListing::class)->handle($lazada, $other);
    app(UpdateListingVariant::class)->handle(
        $onShopee->variants()->where('platform_sku', 'TS-RED-M')->firstOrFail(), 'A-1', null);
    app(UpdateListingVariant::class)->handle(
        $onLazada->variants()->firstOrFail(), 'A-1', null);

    expect(app(ResolvePlatformSku::class)->handle($shopee, 'A-1')->master_sku)->toBe('TS-RED-M')
        ->and(app(ResolvePlatformSku::class)->handle($lazada, 'A-1')->master_sku)->toBe('CUP-1');
});

it('stores the Deal Price as integer satang', function () {
    $shop = marketplaceShop();
    $listing = app(CreateListing::class)->handle($shop, listedProduct());
    $mapping = $listing->variants()->firstOrFail();

    app(UpdateListingVariant::class)->handle($mapping, $mapping->platform_sku, Money::fromBaht('159.50'));

    expect($mapping->refresh()->deal_price?->satang)->toBe(15950);
});

it('fails loud when a new Listing\'s default SKU collides with a mapping that points elsewhere', function () {
    $shop = marketplaceShop();
    $cup = app(CreateProduct::class)->handle('แก้วน้ำ', [
        ['master_sku' => 'CUP-1', 'list_price' => Money::fromBaht('59')],
    ]);
    $shirt = listedProduct();

    // The seller maps the cup's pre-existing platform code — which happens
    // to be the shirt M Variant's Master SKU — before listing the shirt.
    $cupListing = app(CreateListing::class)->handle($shop, $cup);
    app(UpdateListingVariant::class)->handle($cupListing->variants()->firstOrFail(), 'TS-RED-M', null);

    app(CreateListing::class)->handle($shop, $shirt);
})->throws(PlatformSkuConflictException::class, 'TS-RED-M');

function harnessListing(): Listing
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
    $shop = app(CreateShop::class)->handle('ร้าน harness', Platform::Shopee, $location);
    // One Variant — the harness expects its closure to add exactly one row
    // to the table under test (here: one listing, one listing_variant).
    $product = app(CreateProduct::class)->handle('แก้วน้ำ', [
        ['master_sku' => 'CUP-1', 'list_price' => Money::fromBaht('59')],
    ]);

    return app(CreateListing::class)->handle($shop, $product);
}

it('lets an Admin through the Listing screens and blocks a Cashier', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);
    get(ListingResource::getUrl('index'))->assertOk();

    [, $cashier] = tenantWithUser('Cashier');
    actingAs($cashier);
    get(ListingResource::getUrl('index'))->assertForbidden();
});

it('passes the cross-tenant isolation harness (listings)', function () {
    assertTenantIsolation(fn (): Listing => harnessListing());
});

it('passes the cross-tenant isolation harness (listing variants)', function () {
    assertTenantIsolation(fn (): Model => harnessListing()->variants()->firstOrFail());
});

it('creates a Listing whose Variants default their Platform SKU to the Master SKU', function () {
    $shop = marketplaceShop();
    $product = listedProduct();

    $listing = app(CreateListing::class)->handle($shop, $product);

    expect($listing->variants)->toHaveCount(2)
        ->and($listing->variants->pluck('platform_sku')->all())->toBe(['TS-RED-M', 'TS-RED-L'])
        ->and($listing->variants->first()?->deal_price)->toBeNull()
        ->and($listing->variants->first()?->shop_id)->toBe($shop->id);
});

// --- Listing Status (Issue #49) -----------------------------------------------

it('defaults new ListingVariant listing_status to listed', function () {
    $listing = app(CreateListing::class)->handle(marketplaceShop(), listedProduct());

    /** @var ListingVariant $variant */
    $variant = $listing->variants()->firstOrFail();

    expect($variant->listing_status)->toBe(ListingStatus::Listed);
});

it('casts listing_status from string to the ListingStatus enum and back', function () {
    $listing = app(CreateListing::class)->handle(marketplaceShop(), listedProduct());

    /** @var ListingVariant $raw */
    $raw = $listing->variants()->firstOrFail();

    // Cast in: the attribute arrives from the DB as an enum case.
    expect($raw->listing_status)->toBeInstanceOf(ListingStatus::class)
        ->and($raw->listing_status->value)->toBe('listed');

    // Cast out: assigning the enum case persists the correct string value.
    $raw->listing_status = ListingStatus::Draft;
    $raw->save();

    expect($raw->fresh()?->listing_status)->toBe(ListingStatus::Draft)
        ->and($raw->fresh()?->getRawOriginal('listing_status'))->toBe('draft');
});

it('stores and reads draft listing_status', function () {
    $listing = app(CreateListing::class)->handle(marketplaceShop(), listedProduct());

    /** @var ListingVariant $variant */
    $variant = $listing->variants()->firstOrFail();
    $variant->listing_status = ListingStatus::Draft;
    $variant->save();

    expect($variant->fresh()?->listing_status)->toBe(ListingStatus::Draft);
});

it('reads listed when a row is inserted via raw DB without the listing_status column — backfill by DB default', function () {
    // Build a fresh shop + product so we control the exact (listing, variant)
    // combination and avoid any unique-constraint collision.
    $shop = marketplaceShop('backfill-shop');
    $product = app(CreateProduct::class)->handle('Backfill Item', [
        ['master_sku' => 'BF-1', 'list_price' => Money::fromBaht('99')],
    ]);
    $listing = app(CreateListing::class)->handle($shop, $product);

    // CreateListing auto-inserts a ListingVariant with listing_status via
    // Eloquent. Delete it so we can re-insert the same (listing, variant)
    // pair using only the DB DEFAULT — simulating a row that existed before
    // the column was added (CONTEXT.md: Listing Status; Issue #49).
    $auto = $listing->variants()->firstOrFail();
    DB::table('listing_variants')->where('id', $auto->id)->delete();

    // Use the listing's tenant_id directly (TenantContext::current() is
    // nullable per its signature; the listing's FK is not).
    $id = DB::table('listing_variants')->insertGetId([
        'tenant_id' => $listing->tenant_id,
        'listing_id' => $listing->id,
        'shop_id' => $shop->id,
        'variant_id' => $auto->variant_id,
        'platform_sku' => $auto->platform_sku,
        'created_by' => null,  // auth()->id() is null in tests; TracksCreatedBy allows null
        'created_at' => now(),
        'updated_at' => now(),
        // listing_status intentionally omitted — relies on DB DEFAULT 'listed'
    ]);

    /** @var ListingVariant $row */
    $row = ListingVariant::withoutGlobalScopes()->findOrFail($id);

    expect($row->listing_status)->toBe(ListingStatus::Listed);
});
