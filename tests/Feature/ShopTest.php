<?php

use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\Location;
use App\Models\Shop;
use App\Models\ShopSetting;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function fulfilmentLocation(): Location
{
    return Location::query()->where('is_default', true)->firstOrFail();
}

it('creates a marketplace Shop with its Shop Settings and the platform-correct payout anchor', function () {
    $shopee = app(CreateShop::class)->handle('ร้าน Shopee หลัก', Platform::Shopee, fulfilmentLocation());
    $tiktok = app(CreateShop::class)->handle('tiktok1', Platform::Tiktok, fulfilmentLocation());

    expect($shopee->platform_type)->toBe(PlatformType::Marketplace)
        ->and($shopee->settings?->payout_anchor)->toBe('completed_date')
        ->and($tiktok->settings?->payout_anchor)->toBe('delivered_date')
        ->and($shopee->settings?->mismatch_threshold?->satang)->toBe(100)
        ->and($shopee->location?->is_default)->toBeTrue();
});

it('creates a pos Shop with no Shop Settings — they are marketplace money-flow concerns', function () {
    $pos = app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, fulfilmentLocation());

    expect($pos->platform_type)->toBe(PlatformType::Pos)
        ->and($pos->settings)->toBeNull()
        ->and(ShopSetting::query()->count())->toBe(0);
});

it('derives platform_type from the platform', function () {
    expect(Platform::Shopee->type())->toBe(PlatformType::Marketplace)
        ->and(Platform::Lazada->type())->toBe(PlatformType::Marketplace)
        ->and(Platform::Tiktok->type())->toBe(PlatformType::Marketplace)
        ->and(Platform::Line->type())->toBe(PlatformType::Social)
        ->and(Platform::Instagram->type())->toBe(PlatformType::Social)
        ->and(Platform::Facebook->type())->toBe(PlatformType::Social)
        ->and(Platform::Pos->type())->toBe(PlatformType::Pos);
});

it('passes the cross-tenant isolation harness (shops)', function () {
    assertTenantIsolation(function (): Shop {
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);

        return app(CreateShop::class)->handle('ร้าน harness', Platform::Pos, $location);
    });
});

it('passes the cross-tenant isolation harness (shop settings)', function () {
    assertTenantIsolation(function (): ShopSetting {
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle('ร้าน harness', Platform::Shopee, $location);

        return $shop->settings ?? throw new RuntimeException('settings missing');
    });
});
