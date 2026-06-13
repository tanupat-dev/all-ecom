<?php

use App\Actions\Claims\AddClaimEvidenceItem;
use App\Actions\Claims\CreateClaim;
use App\Actions\Claims\SetClaimEvidenceChecked;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\Claim;
use App\Models\ClaimEvidenceItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('EvidenceTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─── helpers (different names from ClaimKernelTest to avoid PHP collision) ────

function evidenceShop(): Shop
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);

    return app(CreateShop::class)->handle('Shopee', Platform::Shopee, $location);
}

function evidenceOrder(?Shop $shop = null): Order
{
    $shop ??= evidenceShop();

    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => 'EVD-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);
}

function evidenceClaim(?Order $order = null): Claim
{
    $order ??= evidenceOrder();

    return app(CreateClaim::class)->handle($order, ClaimType::ShippingOvercharge);
}

// ─── Default seeding (the critical invariant) ─────────────────────────────────

it('seeds exactly 4 evidence items when a Claim is created', function () {
    $claim = evidenceClaim();

    expect($claim->evidenceItems()->count())->toBe(4);
});

it('seeds all default items as unchecked', function () {
    $claim = evidenceClaim();

    expect($claim->evidenceItems()->where('checked', false)->count())->toBe(4);
});

it('seeds all default items with is_default = true', function () {
    $claim = evidenceClaim();

    expect($claim->evidenceItems()->where('is_default', true)->count())->toBe(4);
});

it('seeds the four canonical evidence labels in order', function () {
    $claim = evidenceClaim();

    $labels = $claim->evidenceItems()->orderBy('id')->pluck('label')->all();

    expect($labels)->toBe([
        'Outgoing packing/shipping video',
        'Incoming unboxing video',
        'Weight on scale (before/after)',
        'Photos of received goods',
    ]);
});

// ─── Adding a custom item ──────────────────────────────────────────────────────

it('adds a custom evidence item as unchecked and not default', function () {
    $claim = evidenceClaim();

    $item = app(AddClaimEvidenceItem::class)->handle($claim, 'Courier weight receipt');

    expect($item->label)->toBe('Courier weight receipt')
        ->and($item->checked)->toBeFalse()
        ->and($item->is_default)->toBeFalse()
        ->and($item->claim_id)->toBe($claim->id);
});

it('persists the custom item so the Claim now has 5 evidence items', function () {
    $claim = evidenceClaim();

    app(AddClaimEvidenceItem::class)->handle($claim, 'Courier weight receipt');

    expect($claim->evidenceItems()->count())->toBe(5);
});

// ─── Checking / unchecking ────────────────────────────────────────────────────

it('checks an evidence item and persists the change', function () {
    $claim = evidenceClaim();
    $item = $claim->evidenceItems()->first();
    assert($item instanceof ClaimEvidenceItem);

    app(SetClaimEvidenceChecked::class)->handle($item, true);

    expect($item->refresh()->checked)->toBeTrue();
});

it('unchecks a previously checked evidence item and persists the change', function () {
    $claim = evidenceClaim();
    $item = $claim->evidenceItems()->first();
    assert($item instanceof ClaimEvidenceItem);

    app(SetClaimEvidenceChecked::class)->handle($item, true);
    app(SetClaimEvidenceChecked::class)->handle($item, false);

    expect($item->refresh()->checked)->toBeFalse();
});

it('checking does not affect the other items on the same Claim', function () {
    $claim = evidenceClaim();
    $first = $claim->evidenceItems()->orderBy('id')->first();
    assert($first instanceof ClaimEvidenceItem);

    app(SetClaimEvidenceChecked::class)->handle($first, true);

    expect($claim->evidenceItems()->where('checked', false)->count())->toBe(3);
});

// ─── Policy gates (ADR 0012) ──────────────────────────────────────────────────

it('allows claim.view to read evidence items but not mutate', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $viewRole = Role::findOrCreate('EvidViewer-'.uniqid(), 'web');
    $viewRole->syncPermissions(['claim.view']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($viewRole);

    $claim = evidenceClaim();
    $item = $claim->evidenceItems()->first();

    expect($user->can('viewAny', ClaimEvidenceItem::class))->toBeTrue()
        ->and($user->can('view', $item))->toBeTrue()
        ->and($user->can('create', ClaimEvidenceItem::class))->toBeFalse()
        ->and($user->can('update', $item))->toBeFalse()
        ->and($user->can('delete', $item))->toBeFalse();
});

it('allows claim.manage to mutate evidence items', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $manageRole = Role::findOrCreate('EvidManager-'.uniqid(), 'web');
    $manageRole->syncPermissions(['claim.manage']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($manageRole);

    $claim = evidenceClaim();
    $item = $claim->evidenceItems()->first();

    expect($user->can('create', ClaimEvidenceItem::class))->toBeTrue()
        ->and($user->can('update', $item))->toBeTrue()
        ->and($user->can('delete', $item))->toBeTrue();
});

it('denies everything for a role lacking Claim permissions', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $noRole = Role::findOrCreate('EvidNone-'.uniqid(), 'web');
    $noRole->syncPermissions(['order.view']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($noRole);

    $claim = evidenceClaim();
    $item = $claim->evidenceItems()->first();

    expect($user->can('viewAny', ClaimEvidenceItem::class))->toBeFalse()
        ->and($user->can('view', $item))->toBeFalse()
        ->and($user->can('create', ClaimEvidenceItem::class))->toBeFalse();
});

// ─── Cross-tenant isolation (ADR 0011) ────────────────────────────────────────

it('passes the cross-tenant isolation harness for claim_evidence_items', function () {
    assertTenantIsolation(function (): ClaimEvidenceItem {
        // Build a Claim directly (bypassing CreateClaim) so that exactly ONE
        // ClaimEvidenceItem row is created for this tenant — the harness
        // asserts `newQuery()->pluck('id') === [$rowA->id]`, which breaks if
        // CreateClaim seeds all four default items alongside the one we return.
        $order = evidenceOrder();
        $claim = Claim::query()->create([
            'claim_type' => ClaimType::ShippingOvercharge,
            'status' => ClaimStatus::Eligible,
            'ref_order_id' => $order->id,
            'ref_return_id' => null,
        ]);

        return app(AddClaimEvidenceItem::class)->handle($claim, 'isolation-item');
    });
});
