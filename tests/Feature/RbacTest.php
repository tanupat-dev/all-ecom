<?php

use App\Actions\Authorization\DeleteRole;
use App\Actions\Authorization\SyncUserRoles;
use App\Actions\Tenants\CreateTenant;
use App\Authorization\PermissionCatalogue;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * @return array{Tenant, User}
 */
function tenantWithUser(string $roleName): array
{
    $tenant = app(CreateTenant::class)->handle('A-'.$roleName);
    app(TenantContext::class)->set($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($roleName);

    return [$tenant, $user];
}

it('seeds the editable Admin and Cashier default roles per tenant', function () {
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);

    $admin = Role::findByName('Admin', 'web');
    $cashier = Role::findByName('Cashier', 'web');

    expect($admin->permissions()->count())->toBe(count(PermissionCatalogue::ALL))
        ->and($cashier->hasPermissionTo('pos.checkout'))->toBeTrue()
        ->and($cashier->hasPermissionTo('sale.void'))->toBeFalse();
});

it('scopes roles per tenant — tenant B never sees tenant A roles', function () {
    $tenantA = app(CreateTenant::class)->handle('A');
    $tenantB = app(CreateTenant::class)->handle('B');

    app(TenantContext::class)->set($tenantA);
    Role::findOrCreate('คลังเท่านั้น', 'web');

    app(TenantContext::class)->set($tenantB);

    expect(Role::query()->pluck('name')->all())->toBe(['Admin', 'Cashier']);
});

it('lets an Admin through a permission-gated page and blocks a Cashier', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);
    get(LocationResource::getUrl('index'))->assertOk();

    [, $cashier] = tenantWithUser('Cashier');
    actingAs($cashier);
    get(LocationResource::getUrl('index'))->assertForbidden();
    get(ProductResource::getUrl('index'))->assertOk(); // product.view is in the POS subset
});

it('gates by permission, never by role name', function () {
    [$tenant] = tenantWithUser('Admin');
    $custom = Role::findOrCreate('ผู้จัดการคลัง', 'web');
    $custom->syncPermissions(['location.view']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($custom);

    actingAs($user);

    get(LocationResource::getUrl('index'))->assertOk();
});

it('refuses a role change that would lock the tenant out', function () {
    [, $admin] = tenantWithUser('Admin');
    $cashierRole = Role::findByName('Cashier', 'web');

    app(SyncUserRoles::class)->handle($admin, [$cashierRole]);
})->throws(LogicException::class, 'lock-out safeguard');

it('deleting a role in use strips it from users first', function () {
    [$tenant, $admin] = tenantWithUser('Admin');
    $cashierRole = Role::findByName('Cashier', 'web');
    $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
    $cashier->assignRole($cashierRole);

    $stripped = app(DeleteRole::class)->handle($cashierRole, $tenant->id);

    expect($stripped)->toBe(1)
        ->and($cashier->fresh()?->roles()->count())->toBe(0)
        ->and(Role::query()->pluck('name')->all())->toBe(['Admin']);
});

it('refuses deleting the last role that keeps the tenant manageable', function () {
    [$tenant] = tenantWithUser('Admin');
    $adminRole = Role::findByName('Admin', 'web');

    app(DeleteRole::class)->handle($adminRole, $tenant->id);
})->throws(LogicException::class, 'lock-out safeguard');
