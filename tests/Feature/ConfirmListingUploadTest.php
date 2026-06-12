<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\ConfirmListingUpload;
use App\Actions\Listings\CreateListing;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ListingStatus;
use App\Enums\Platform;
use App\Filament\Pages\ListingCoverage;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\RelationManagers\VariantsRelationManager;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─── helpers ───────────────────────────────────────────────────────────────

function confirmShop(string $name = 'Shopee-Confirm'): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle($name, Platform::Shopee, $location);
}

function confirmListing(string $sku = 'CU-1'): Listing
{
    $product = app(CreateProduct::class)->handle("สินค้า {$sku}", [
        ['master_sku' => $sku, 'list_price' => Money::fromBaht('100')],
    ]);

    return app(CreateListing::class)->handle(confirmShop("shop-{$sku}"), $product);
}

/**
 * Returns a draft ListingVariant within the current tenant context.
 */
function draftVariant(string $sku = 'DR-1'): ListingVariant
{
    $listing = confirmListing($sku);
    /** @var ListingVariant $lv */
    $lv = $listing->variants()->firstOrFail();
    $lv->update(['listing_status' => ListingStatus::Draft->value]);

    return $lv->refresh();
}

// ─── Action: core transition ────────────────────────────────────────────────

it('transitions a draft ListingVariant to listed', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $lv = draftVariant('TR-1');

    app(ConfirmListingUpload::class)->handle($lv);

    expect($lv->refresh()->listing_status)->toBe(ListingStatus::Listed);
});

it('is idempotent — confirming an already-listed row is a no-op', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    // CreateListing defaults to listed — no state change needed.
    $listing = confirmListing('NOP-1');
    /** @var ListingVariant $lv */
    $lv = $listing->variants()->firstOrFail();

    expect($lv->listing_status)->toBe(ListingStatus::Listed);

    // Confirm twice — second call must not throw or corrupt state.
    app(ConfirmListingUpload::class)->handle($lv);
    app(ConfirmListingUpload::class)->handle($lv->refresh());

    expect($lv->refresh()->listing_status)->toBe(ListingStatus::Listed);
});

// ─── RBAC ──────────────────────────────────────────────────────────────────

it('rejects a user without listing.manage — throws AuthorizationException', function () {
    // Set up the tenant & draft row under Admin.
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);
    $lv = draftVariant('RBAC-1');

    // Switch to a Cashier who lacks listing.manage.
    [$tenant] = tenantWithUser('Cashier');
    $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
    $cashier->assignRole('Cashier');
    actingAs($cashier);

    app(ConfirmListingUpload::class)->handle($lv);
})->throws(AuthorizationException::class);

// ─── Cross-tenant isolation ─────────────────────────────────────────────────

it('cannot confirm another tenant\'s ListingVariant — cross-tenant guard', function () {
    // Tenant A: create a draft row.
    [, $adminA] = tenantWithUser('Admin');
    actingAs($adminA);
    $lvA = draftVariant('CT-A1');

    // Tenant B: a fully separate Admin.
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);
    $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
    $userB->assignRole('Admin');
    actingAs($userB);

    // Tenant B tries to confirm Tenant A's row — must be rejected.
    // (The Policy's `can('update', $lv)` checks the resource owner via
    //  BelongsToTenant + policy; the row's tenant_id != B.)
    app(ConfirmListingUpload::class)->handle($lvA);
})->throws(AuthorizationException::class);

// ─── Coverage page: badge reflects the change ──────────────────────────────

it('the Coverage matrix badge moves from ร่าง to ลงแล้ว after confirmation', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $lv = draftVariant('CV-1');

    // Before confirmation: draft badge visible.
    Livewire::test(ListingCoverage::class)
        ->assertSee(ListingStatus::Draft->getLabel());

    app(ConfirmListingUpload::class)->handle($lv);

    // After confirmation: listed badge visible, draft gone.
    Livewire::test(ListingCoverage::class)
        ->assertSee(ListingStatus::Listed->getLabel())
        ->assertDontSee(ListingStatus::Draft->getLabel());
});

// ─── Relation Manager UI ────────────────────────────────────────────────────

it('the confirm action is visible on a draft row in the VariantsRelationManager', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $lv = draftVariant('RM-1');
    /** @var Listing $listing */
    $listing = $lv->listing()->firstOrFail();

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $listing,
        'pageClass' => EditListing::class,
    ])
        ->assertTableActionVisible('confirmUpload', $lv);
});

it('the confirm action is hidden on an already-listed row', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $listing = confirmListing('RM-LISTED');
    /** @var ListingVariant $lv */
    $lv = $listing->variants()->firstOrFail();

    expect($lv->listing_status)->toBe(ListingStatus::Listed);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $listing,
        'pageClass' => EditListing::class,
    ])
        ->assertTableActionHidden('confirmUpload', $lv);
});

it('calling confirmUpload from the VariantsRelationManager flips the status to listed', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $lv = draftVariant('RM-FLIP');
    /** @var Listing $listing */
    $listing = $lv->listing()->firstOrFail();

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $listing,
        'pageClass' => EditListing::class,
    ])
        ->callTableAction('confirmUpload', $lv);

    expect($lv->refresh()->listing_status)->toBe(ListingStatus::Listed);
});
