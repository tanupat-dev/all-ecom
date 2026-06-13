<?php

use App\Actions\Claims\CreateClaim;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Models\Claim;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('ClaimTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─── helpers ──────────────────────────────────────────────────────────────────

function claimShop(): Shop
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);

    return app(CreateShop::class)->handle('Shopee', Platform::Shopee, $location);
}

function claimOrder(?Shop $shop = null): Order
{
    $shop ??= claimShop();

    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => 'CLM-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);
}

function claimReturn(Order $order, Shop $shop): OrderReturn
{
    return OrderReturn::query()->create([
        'shop_id' => $shop->id,
        'platform_return_id' => 'RET-'.uniqid(),
        'ref_order_id' => $order->id,
        'return_type' => ReturnType::ReturnAndRefund,
        'sub_status' => ReturnSubStatus::Received,
    ]);
}

// ─── CreateClaim: type↔ref invariant ──────────────────────────────────────────

it('creates a return_fee Claim attached to a Return', function () {
    $shop = claimShop();
    $order = claimOrder($shop);
    $return = claimReturn($order, $shop);

    $claim = app(CreateClaim::class)->handle($order, ClaimType::ReturnFee, $return);

    expect($claim->claim_type)->toBe(ClaimType::ReturnFee)
        ->and($claim->ref_order_id)->toBe($order->id)
        ->and($claim->ref_return_id)->toBe($return->id)
        ->and($claim->orderReturn()->firstOrFail()->id)->toBe($return->id)
        ->and($claim->order()->firstOrFail()->id)->toBe($order->id);
});

it('creates a shipping_overcharge Claim attached to the Order alone', function () {
    $order = claimOrder();

    $claim = app(CreateClaim::class)->handle($order, ClaimType::ShippingOvercharge);

    expect($claim->claim_type)->toBe(ClaimType::ShippingOvercharge)
        ->and($claim->ref_order_id)->toBe($order->id)
        ->and($claim->ref_return_id)->toBeNull();
});

it('rejects a return_fee Claim with no Return', function () {
    $order = claimOrder();

    expect(fn () => app(CreateClaim::class)->handle($order, ClaimType::ReturnFee))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a shipping_overcharge Claim that names a Return', function () {
    $shop = claimShop();
    $order = claimOrder($shop);
    $return = claimReturn($order, $shop);

    expect(fn () => app(CreateClaim::class)->handle($order, ClaimType::ShippingOvercharge, $return))
        ->toThrow(InvalidArgumentException::class);
});

// ─── default status ───────────────────────────────────────────────────────────

it('defaults a new Claim to eligible', function () {
    $order = claimOrder();

    $claim = app(CreateClaim::class)->handle($order, ClaimType::ShippingOvercharge);

    expect($claim->status)->toBe(ClaimStatus::Eligible);
});

// ─── ClaimStatus::isTerminal ──────────────────────────────────────────────────

it('marks approved/rejected/abandoned terminal and the rest non-terminal', function () {
    expect(ClaimStatus::Approved->isTerminal())->toBeTrue()
        ->and(ClaimStatus::Rejected->isTerminal())->toBeTrue()
        ->and(ClaimStatus::Abandoned->isTerminal())->toBeTrue()
        ->and(ClaimStatus::Eligible->isTerminal())->toBeFalse()
        ->and(ClaimStatus::SubmittedInitial->isTerminal())->toBeFalse()
        ->and(ClaimStatus::SubmittedTicket->isTerminal())->toBeFalse();
});

// ─── Permissions (ADR 0012) ───────────────────────────────────────────────────

it('seeds claim.view and claim.manage and grants both to Admin', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('Admin');

    expect($admin->checkPermissionTo('claim.view'))->toBeTrue()
        ->and($admin->checkPermissionTo('claim.manage'))->toBeTrue();
});

it('does not grant Claim permissions to a Cashier', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
    $cashier->assignRole('Cashier');

    expect($cashier->checkPermissionTo('claim.view'))->toBeFalse()
        ->and($cashier->checkPermissionTo('claim.manage'))->toBeFalse();
});

// ─── ClaimPolicy gates ────────────────────────────────────────────────────────

it('allows view for a user with claim.view but denies mutation', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $viewRole = Role::findOrCreate('ClaimViewer-'.uniqid(), 'web');
    $viewRole->syncPermissions(['claim.view']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($viewRole);

    $order = claimOrder();
    $claim = app(CreateClaim::class)->handle($order, ClaimType::ShippingOvercharge);

    expect($user->can('viewAny', Claim::class))->toBeTrue()
        ->and($user->can('view', $claim))->toBeTrue()
        ->and($user->can('create', Claim::class))->toBeFalse()
        ->and($user->can('update', $claim))->toBeFalse()
        ->and($user->can('delete', $claim))->toBeFalse();
});

it('allows mutation for a user with claim.manage', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $manageRole = Role::findOrCreate('ClaimManager-'.uniqid(), 'web');
    $manageRole->syncPermissions(['claim.manage']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($manageRole);

    $order = claimOrder();
    $claim = app(CreateClaim::class)->handle($order, ClaimType::ShippingOvercharge);

    expect($user->can('create', Claim::class))->toBeTrue()
        ->and($user->can('update', $claim))->toBeTrue()
        ->and($user->can('delete', $claim))->toBeTrue();
});

it('denies everything for a role lacking Claim permissions', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $noneRole = Role::findOrCreate('NoClaim-'.uniqid(), 'web');
    $noneRole->syncPermissions(['order.view']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($noneRole);

    $order = claimOrder();
    $claim = app(CreateClaim::class)->handle($order, ClaimType::ShippingOvercharge);

    expect($user->can('viewAny', Claim::class))->toBeFalse()
        ->and($user->can('view', $claim))->toBeFalse()
        ->and($user->can('create', Claim::class))->toBeFalse();
});

// ─── Cross-tenant isolation (ADR 0011) ────────────────────────────────────────

it('passes the cross-tenant isolation harness', function () {
    assertTenantIsolation(function (): Claim {
        $order = claimOrder();

        return app(CreateClaim::class)->handle($order, ClaimType::ShippingOvercharge);
    });
});
