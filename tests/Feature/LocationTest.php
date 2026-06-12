<?php

use App\Actions\Tenants\CreateTenant;
use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('auto-provisions one default Location when a Tenant is created', function () {
    $tenant = app(CreateTenant::class)->handle('ร้านทดสอบ');

    app(TenantContext::class)->set($tenant);

    $locations = Location::query()->get();

    expect($locations)->toHaveCount(1)
        ->and($locations->first()?->is_default)->toBeTrue()
        ->and($locations->first()?->tenant_id)->toBe($tenant->id);
});

it('refuses to delete the default Location', function () {
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);

    Location::query()->firstOrFail()->delete();
})->throws(LogicException::class, 'default Location cannot be deleted');

it('allows deleting a non-default Location', function () {
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
    $stockroom = Location::query()->create(['name' => 'คลังรอง']);

    $stockroom->delete();

    expect(Location::query()->count())->toBe(1);
});

it('enforces exactly one default Location per Tenant at the database', function () {
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);

    DB::transaction(function () {
        Location::query()->create(['name' => 'อีกอันที่อ้างเป็น default', 'is_default' => true]);
    });
})->throws(QueryException::class, 'locations_one_default_per_tenant');

it('passes the cross-tenant isolation harness', function () {
    assertTenantIsolation(fn (): Location => Location::query()->create(['name' => 'คลัง']));
});

it('lists only the current tenant locations in the panel', function () {
    $tenant = app(CreateTenant::class)->handle('A');
    $other = app(CreateTenant::class)->handle('B');

    app(TenantContext::class)->set($other);
    Location::query()->create(['name' => 'คลังของอีกร้าน']);

    app(TenantContext::class)->set($tenant);
    actingAs(User::factory()->create());

    get(LocationResource::getUrl('index'))
        ->assertOk()
        ->assertSee('คลังหลัก')
        ->assertDontSee('คลังของอีกร้าน');
});
