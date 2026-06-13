<?php

/**
 * Campaign expiry reminder (Issue #77; CONTEXT.md: Promotion).
 *
 * Covers:
 *   – A campaign ending within 48h appears in the list and the command counts it
 *   – A campaign ending beyond 48h does NOT appear
 *   – An already-ended campaign does NOT appear
 *   – A `base` Promotion (no end_at) does NOT appear
 *   – Extending end_at past the window removes the campaign from the list
 *   – The command is idempotent (re-running gives the same count, no side-effects)
 *   – Cross-tenant: another tenant's expiring campaign is invisible to tenant A
 */

use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Promotions\CreatePromotion;
use App\Actions\Promotions\PromotionLineInput;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\Platform;
use App\Enums\PromotionType;
use App\Filament\Resources\ExpiringCampaigns\ExpiringCampaignResource;
use App\Models\ListingVariant;
use App\Models\Location;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow('2026-06-13 12:00:00');
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a Listing with a unique Variant for the current tenant context.
 * Returns the first ListingVariant so a Promotion Line can be attached.
 */
function expiryListing(): ListingVariant
{
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $shop = app(CreateShop::class)->handle('shop-'.Str::random(6), Platform::Shopee, $location);
    $product = app(CreateProduct::class)->handle('สินค้า', [
        ['master_sku' => 'SKU-'.Str::random(8), 'list_price' => Money::fromBaht('200')],
    ]);
    $listing = app(CreateListing::class)->handle($shop, $product);

    return $listing->variants()->firstOrFail();
}

/**
 * Create a Campaign Promotion whose window ends at $endAt.
 * $startAt defaults to 1h before now (campaign already active).
 */
function expiryCampaign(Carbon $endAt, ?Carbon $startAt = null): Promotion
{
    return app(CreatePromotion::class)->handle(
        PromotionType::Campaign,
        'Camp-'.Str::random(4),
        [PromotionLineInput::dealPrice(expiryListing(), Money::fromBaht('150'))],
        $startAt ?? now()->subHour(),
        $endAt,
    );
}

// ---------------------------------------------------------------------------
// 1. Campaign ending within 48h appears in the expiry list
// ---------------------------------------------------------------------------

it('a campaign ending within 48h appears in the expiry list', function () {
    // end_at = now + 24h → inside the 48h window
    $campaign = expiryCampaign(now()->addHours(24));

    $ids = ExpiringCampaignResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($campaign->id);
});

// ---------------------------------------------------------------------------
// 2. Campaign ending beyond 48h does NOT appear
// ---------------------------------------------------------------------------

it('a campaign ending beyond 48h does not appear in the expiry list', function () {
    // end_at = now + 72h → outside the 48h window
    $campaign = expiryCampaign(now()->addHours(72));

    $ids = ExpiringCampaignResource::getEloquentQuery()->pluck('id');

    expect($ids)->not->toContain($campaign->id);
});

// ---------------------------------------------------------------------------
// 3. Already-ended campaign does NOT appear
// ---------------------------------------------------------------------------

it('an already-ended campaign does not appear in the expiry list', function () {
    // end_at = 1h ago → campaign has already ended
    $campaign = expiryCampaign(now()->subHour(), now()->subDays(2));

    $ids = ExpiringCampaignResource::getEloquentQuery()->pluck('id');

    expect($ids)->not->toContain($campaign->id);
});

// ---------------------------------------------------------------------------
// 4. Base Promotion (no end_at) does NOT appear
// ---------------------------------------------------------------------------

it('a base Promotion (no end_at) does not appear in the expiry list', function () {
    $base = app(CreatePromotion::class)->handle(
        PromotionType::Base,
        'BasePromo',
        [PromotionLineInput::dealPrice(expiryListing(), Money::fromBaht('180'))],
    );

    $ids = ExpiringCampaignResource::getEloquentQuery()->pluck('id');

    expect($ids)->not->toContain($base->id);
});

// ---------------------------------------------------------------------------
// 5. Extending end_at past the window removes the campaign from the list
// ---------------------------------------------------------------------------

it('extending end_at past the 48h window removes the campaign from the list', function () {
    // Initially within window
    $campaign = expiryCampaign(now()->addHours(24));

    expect(ExpiringCampaignResource::getEloquentQuery()->pluck('id'))->toContain($campaign->id);

    // Extend to 72h — outside the window
    $campaign->update(['end_at' => now()->addHours(72)]);

    expect(ExpiringCampaignResource::getEloquentQuery()->pluck('id'))->not->toContain($campaign->id);
});

// ---------------------------------------------------------------------------
// 6. The command counts the expiring campaign and is idempotent
// ---------------------------------------------------------------------------

it('the command counts the expiring campaign and is idempotent — running twice gives the same result', function () {
    expiryCampaign(now()->addHours(24));

    // First run
    $exitFirst = Artisan::call('promotions:expiring');
    $outputFirst = Artisan::output();

    expect($exitFirst)->toBe(0)
        ->and($outputFirst)->toContain('1 campaign(s)');

    // Second run — idempotent: same count, same exit code, no duplication
    $exitSecond = Artisan::call('promotions:expiring');
    $outputSecond = Artisan::output();

    expect($exitSecond)->toBe(0)
        ->and($outputSecond)->toBe($outputFirst);
});

// ---------------------------------------------------------------------------
// 7. Cross-tenant: another tenant's expiring campaign is not visible
// ---------------------------------------------------------------------------

it('cross-tenant: the expiry list and command show only the current tenant campaigns', function () {
    // Tenant A (from beforeEach) — create an expiring campaign
    $campaignA = expiryCampaign(now()->addHours(24));

    // Switch to Tenant B — create its own expiring campaign
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);

    $campaignB = expiryCampaign(now()->addHours(24));

    // While in Tenant B context — only B's campaign is visible
    $idsB = ExpiringCampaignResource::getEloquentQuery()->pluck('id');
    expect($idsB)->toContain($campaignB->id)
        ->and($idsB)->not->toContain($campaignA->id);

    // Switch back to Tenant A — only A's campaign is visible
    app(TenantContext::class)->forget();
    $tenantA = Tenant::query()->withoutGlobalScopes()->where('name', 'A')->firstOrFail();
    app(TenantContext::class)->set($tenantA);

    $idsA = ExpiringCampaignResource::getEloquentQuery()->pluck('id');
    expect($idsA)->toContain($campaignA->id)
        ->and($idsA)->not->toContain($campaignB->id);

    // The command reports counts per tenant — Tenant A sees 1, Tenant B sees 1
    $exit = Artisan::call('promotions:expiring');
    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('1 campaign(s)');
});
