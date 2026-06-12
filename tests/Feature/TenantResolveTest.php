<?php

use App\Actions\Tenants\CreateTenant;
use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Models\User;
use App\Tenancy\TenantContext;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('resolves the tenant from the authenticated user on every request', function () {
    $tenantA = app(CreateTenant::class)->handle('A');
    $tenantB = app(CreateTenant::class)->handle('B');

    app(TenantContext::class)->set($tenantB);
    Location::query()->create(['name' => 'คลังของ B']);
    app(TenantContext::class)->forget();

    app(TenantContext::class)->set($tenantA);
    $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
    $userA->assignRole('Admin');
    app(TenantContext::class)->forget();

    actingAs($userA);

    get(LocationResource::getUrl('index'))
        ->assertOk()
        ->assertSee('คลังหลัก')
        ->assertDontSee('คลังของ B');

    expect(app(TenantContext::class)->current()?->id)->toBe($tenantA->id);
});

it('leaves no tenant context for a user without a tenant', function () {
    actingAs(User::factory()->create(['tenant_id' => null]));

    get('/admin')->assertOk();

    expect(app(TenantContext::class)->current())->toBeNull();
});

it('belongs to its Tenant', function () {
    $tenant = app(CreateTenant::class)->handle('A');
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    expect($user->tenant?->id)->toBe($tenant->id);
});
