<?php

use App\Actions\Pos\CreateRegister;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\Platform;
use App\Models\Location;
use App\Models\Register;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('auto-provisions one default Register when a pos Shop is created', function () {
    $shop = app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, Location::query()->firstOrFail());

    $registers = Register::query()->where('shop_id', $shop->id)->get();

    expect($registers)->toHaveCount(1)
        ->and($registers->first()?->active)->toBeTrue();
});

it('creates no Register for a non-pos Shop', function () {
    app(CreateShop::class)->handle('Shopee', Platform::Shopee, Location::query()->firstOrFail());

    expect(Register::query()->count())->toBe(0);
});

it('refuses a Register on a non-pos Shop', function () {
    $shop = app(CreateShop::class)->handle('LINE', Platform::Line, Location::query()->firstOrFail());

    app(CreateRegister::class)->handle($shop, 'เคาน์เตอร์ 2');
})->throws(InvalidArgumentException::class, 'pos Shop');

it('passes the cross-tenant isolation harness', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): Register {
        $sequence++;
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle("หน้าร้าน {$sequence}", Platform::Pos, $location);

        return Register::query()->where('shop_id', $shop->id)->firstOrFail();
    });
});
