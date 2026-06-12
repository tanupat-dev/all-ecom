<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ListingStatus;
use App\Enums\Platform;
use App\Filament\Pages\ListingCoverage;
use App\Models\Location;
use App\Models\Shop;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
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
    app(TenantContext::class)->forget();
});

// ─── helpers ───────────────────────────────────────────────────────────────

/**
 * Create a marketplace Shop with the default Location.
 */
function coverageShop(string $name, Platform $platform = Platform::Shopee): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle($name, $platform, $location);
}

/**
 * Create a Product with one Variant.
 */
function coverageVariant(string $sku): Variant
{
    return app(CreateProduct::class)
        ->handle("สินค้า {$sku}", [['master_sku' => $sku, 'list_price' => Money::fromBaht('100')]])
        ->variants
        ->firstOrFail();
}

// ─── matrix display ────────────────────────────────────────────────────────

it('shows listed and draft status badges per shop in the matrix', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shopee = coverageShop('Shopee1');
    $lazada = coverageShop('Lazada1', Platform::Lazada);
    $variant = coverageVariant('TS-1');

    // List on Shopee only; mark the Lazada slot as draft explicitly
    $listing = app(CreateListing::class)->handle($shopee, $variant->product()->firstOrFail());
    $listingL = app(CreateListing::class)->handle($lazada, $variant->product()->firstOrFail());
    $listingL->variants()->update(['listing_status' => ListingStatus::Draft->value]);

    Livewire::test(ListingCoverage::class)
        ->assertCanSeeTableRecords([$variant])
        ->assertSee(ListingStatus::Listed->getLabel())   // ลงแล้ว shown for Shopee
        ->assertSee(ListingStatus::Draft->getLabel());   // ร่าง shown for Lazada
});

it('shows a gap marker for a Variant that has no listing on a given Shop', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shopee = coverageShop('Shopee1');
    $lazada = coverageShop('Lazada1', Platform::Lazada);
    $variant = coverageVariant('TS-GAP');

    // Listed only on Shopee — Lazada is a gap
    app(CreateListing::class)->handle($shopee, $variant->product()->firstOrFail());

    Livewire::test(ListingCoverage::class)
        ->assertCanSeeTableRecords([$variant])
        ->assertSee('—');   // gap marker visible somewhere on the page
});

// ─── gap filter ────────────────────────────────────────────────────────────

it('gap filter returns only Variants with no listing on the selected Shop', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shopee = coverageShop('Shopee1');
    $lazada = coverageShop('Lazada1', Platform::Lazada);

    $listed = coverageVariant('TS-LISTED');   // listed on both
    $unlisted = coverageVariant('TS-UNLISTED'); // listed on Shopee only

    $product1 = $listed->product()->firstOrFail();
    $product2 = $unlisted->product()->firstOrFail();

    app(CreateListing::class)->handle($shopee, $product1);
    app(CreateListing::class)->handle($lazada, $product1);
    app(CreateListing::class)->handle($shopee, $product2);
    // $product2 deliberately NOT listed on Lazada

    Livewire::test(ListingCoverage::class)
        ->filterTable('gap_shop', $lazada->id)
        ->assertCanSeeTableRecords([$unlisted])
        ->assertCanNotSeeTableRecords([$listed]);
});

it('gap filter shows all Variants when no Shop is selected', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shopee = coverageShop('Shopee1');
    $v1 = coverageVariant('V1');
    $v2 = coverageVariant('V2');

    app(CreateListing::class)->handle($shopee, $v1->product()->firstOrFail());
    // $v2 has no listing at all

    Livewire::test(ListingCoverage::class)
        ->assertCanSeeTableRecords([$v1, $v2]);
});

// ─── POS shops excluded from columns ───────────────────────────────────────

it('excludes POS shops from the coverage columns', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $location = Location::query()->where('is_default', true)->firstOrFail();
    $posShop = app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, $location);
    $shopee = coverageShop('Shopee1');

    coverageVariant('TS-POS');

    // The POS shop name should NOT appear as a column heading
    Livewire::test(ListingCoverage::class)
        ->assertDontSee('หน้าร้าน')
        ->assertSee('Shopee1');
});

// ─── RBAC ──────────────────────────────────────────────────────────────────

it('lets an Admin (listing.view) access the coverage page', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    get(ListingCoverage::getUrl())->assertOk();
});

it('returns 403 for a user without the listing.view permission', function () {
    [$tenant] = tenantWithUser('Admin');

    $blind = Role::findOrCreate('ไม่เห็น Listing', 'web');
    $blind->syncPermissions(['product.view']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($blind);

    actingAs($user);

    get(ListingCoverage::getUrl())->assertForbidden();
});

// ─── cross-tenant isolation ────────────────────────────────────────────────

it('never shows another tenant\'s Variants or Shops in the matrix', function () {
    // Tenant A: has one variant + shop
    [, $adminA] = tenantWithUser('Admin');
    actingAs($adminA);

    $shopA = coverageShop('Shopee-A');
    $variantA = coverageVariant('VARIANT-A');
    app(CreateListing::class)->handle($shopA, $variantA->product()->firstOrFail());

    // Tenant B: completely separate
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);

    $locationB = Location::query()->where('is_default', true)->firstOrFail();
    $shopB = app(CreateShop::class)->handle('Shopee-B', Platform::Shopee, $locationB);
    $variantB = coverageVariant('VARIANT-B');
    app(CreateListing::class)->handle($shopB, $variantB->product()->firstOrFail());

    $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
    $userB->assignRole('Admin');
    actingAs($userB);

    // When B looks at coverage, only B's data is visible
    Livewire::test(ListingCoverage::class)
        ->assertCanSeeTableRecords([$variantB])
        ->assertCanNotSeeTableRecords([$variantA])
        ->assertSee('Shopee-B')
        ->assertDontSee('Shopee-A')
        ->assertDontSee('VARIANT-A');
});
