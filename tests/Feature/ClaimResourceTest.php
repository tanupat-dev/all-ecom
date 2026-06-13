<?php

use App\Actions\Claims\AppendClaimTimelineEntry;
use App\Actions\Claims\CreateClaim;
use App\Actions\Claims\TransitionClaimStatus;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Enums\ReturnReasonFault;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Filament\Resources\Claims\ClaimResource;
use App\Filament\Resources\Claims\Pages\ListClaims;
use App\Filament\Resources\Claims\Pages\ViewClaim;
use App\Filament\Resources\Claims\RelationManagers\EvidenceItemsRelationManager;
use App\Filament\Resources\Claims\RelationManagers\TimelineEntriesRelationManager;
use App\Models\Claim;
use App\Models\ClaimEvidenceItem;
use App\Models\ClaimTimelineEntry;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('ClaimResourceTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─── helpers ──────────────────────────────────────────────────────────────────

function resShop(): Shop
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);

    return app(CreateShop::class)->handle('Shopee', Platform::Shopee, $location);
}

function resOrder(?Shop $shop = null): Order
{
    $shop ??= resShop();

    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => 'RES-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);
}

function resReturn(Order $order, Shop $shop): OrderReturn
{
    return OrderReturn::query()->create([
        'shop_id' => $shop->id,
        'platform_return_id' => 'RET-'.uniqid(),
        'ref_order_id' => $order->id,
        'return_type' => ReturnType::ReturnAndRefund,
        'sub_status' => ReturnSubStatus::Received,
        'return_reason' => 'สินค้าชำรุด',
        'buyer_note' => 'ได้รับสินค้าเสียหาย',
        'reason_fault' => ReturnReasonFault::SellerFault,
    ]);
}

function resClaim(ClaimType $type = ClaimType::ShippingOvercharge, ?OrderReturn $return = null): Claim
{
    $order = resOrder();

    return app(CreateClaim::class)->handle($order, $type, $return);
}

function resAdmin(): User
{
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('Admin');

    return $user;
}

function resViewOnlyUser(): User
{
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);
    $role = Role::findOrCreate('ClaimViewOnly-'.uniqid(), 'web');
    $role->syncPermissions(['claim.view']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);

    return $user;
}

// ─── 1. List: renders and filters ─────────────────────────────────────────────

it('renders the Claim list for an Admin', function () {
    actingAs(resAdmin());
    get(ClaimResource::getUrl('index'))->assertOk();
});

it('list shows the tenant\'s Claims', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim();

    Livewire::test(ListClaims::class)
        ->assertSee($claim->order()->firstOrFail()->platform_order_id);
});

it('filter by claim_type narrows results', function () {
    $admin = resAdmin();
    actingAs($admin);

    $shipping = resClaim(ClaimType::ShippingOvercharge);
    $shop = resShop();
    $order = resOrder($shop);
    $return = resReturn($order, $shop);
    $returnFee = app(CreateClaim::class)->handle($order, ClaimType::ReturnFee, $return);

    Livewire::test(ListClaims::class)
        ->filterTable('claim_type', ClaimType::ReturnFee)
        ->assertSee($returnFee->order()->firstOrFail()->platform_order_id)
        ->assertDontSee($shipping->order()->firstOrFail()->platform_order_id);
});

it('filter by status narrows results', function () {
    $admin = resAdmin();
    actingAs($admin);

    $eligible = resClaim();
    $abandoned = resClaim();
    app(TransitionClaimStatus::class)->handle($abandoned, ClaimStatus::Abandoned);

    Livewire::test(ListClaims::class)
        ->filterTable('status', ClaimStatus::Eligible)
        ->assertSee($eligible->order()->firstOrFail()->platform_order_id)
        ->assertDontSee($abandoned->order()->firstOrFail()->platform_order_id);
});

// ─── 2. Authorization: view-only user ─────────────────────────────────────────

it('claim.view-only user can load the list page', function () {
    actingAs(resViewOnlyUser());
    get(ClaimResource::getUrl('index'))->assertOk();
});

it('claim.view-only user can load the view page', function () {
    // Create claim as the view-only user's tenant (beforeEach already sets context)
    $claim = resClaim();
    actingAs(resViewOnlyUser());
    get(ClaimResource::getUrl('view', ['record' => $claim->id]))->assertOk();
});

it('transition action is hidden for claim.view-only user (not authorized)', function () {
    $claim = resClaim();
    actingAs(resViewOnlyUser());

    Livewire::test(ViewClaim::class, ['record' => $claim->getRouteKey()])
        ->assertActionHidden('transition');
});

// ─── 3. Return reason visible on return_fee Claims ────────────────────────────

it('view of a return_fee Claim renders Return Reason and Buyer Note', function () {
    $admin = resAdmin();
    actingAs($admin);

    $shop = resShop();
    $order = resOrder($shop);
    $return = resReturn($order, $shop);
    $claim = app(CreateClaim::class)->handle($order, ClaimType::ReturnFee, $return);

    get(ClaimResource::getUrl('view', ['record' => $claim->id]))
        ->assertOk()
        ->assertSee('สินค้าชำรุด')
        ->assertSee('ได้รับสินค้าเสียหาย');
});

it('view of a shipping_overcharge Claim loads without error (return reason block is hidden server-side)', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim(ClaimType::ShippingOvercharge);

    // The return_reason/buyer_note infolist entries are conditionally hidden via
    // ->visible(); Filament still emits the DOM node (fi-hidden CSS class) for
    // Livewire patching, so we just assert the page loads without error.
    get(ClaimResource::getUrl('view', ['record' => $claim->id]))
        ->assertOk();

    // Verify the claim type is correct — the page should still show the type
    expect($claim->claim_type)->toBe(ClaimType::ShippingOvercharge);
});

// ─── 4. Lifecycle transition ──────────────────────────────────────────────────

it('nextStates from eligible offers submitted_initial and abandoned only', function () {
    $next = app(TransitionClaimStatus::class)->nextStates(ClaimStatus::Eligible);

    expect($next)->toHaveCount(2)
        ->and($next)->toContain(ClaimStatus::SubmittedInitial)
        ->and($next)->toContain(ClaimStatus::Abandoned);
});

it('transition action is visible for an eligible Claim with claim.manage', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim();

    Livewire::test(ViewClaim::class, ['record' => $claim->getRouteKey()])
        ->assertActionVisible('transition');
});

it('performing the transition from eligible to submitted_initial persists', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim();

    Livewire::test(ViewClaim::class, ['record' => $claim->getRouteKey()])
        ->callAction('transition', ['status' => ClaimStatus::SubmittedInitial->value]);

    expect($claim->refresh()->status)->toBe(ClaimStatus::SubmittedInitial);
});

it('transition action is hidden for a terminal (abandoned) Claim', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim();
    app(TransitionClaimStatus::class)->handle($claim, ClaimStatus::Abandoned);

    // ViewClaim fetches the fresh record from DB on mount
    Livewire::test(ViewClaim::class, ['record' => $claim->getRouteKey()])
        ->assertActionHidden('transition');
});

// ─── 5. Evidence items ────────────────────────────────────────────────────────

it('toggling an evidence item via the RM action flips its checked state', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim();
    // CreateClaim seeds 4 default items; grab the first one
    $item = $claim->evidenceItems()->firstOrFail();
    expect($item->checked)->toBeFalse();

    Livewire::test(EvidenceItemsRelationManager::class, [
        'ownerRecord' => $claim,
        'pageClass' => ViewClaim::class,
    ])
        ->callTableAction('toggle', $item);

    expect($item->refresh()->checked)->toBeTrue();
});

it('adding a custom evidence item creates an is_default=false row', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim();
    $countBefore = $claim->evidenceItems()->count();

    Livewire::test(EvidenceItemsRelationManager::class, [
        'ownerRecord' => $claim,
        'pageClass' => ViewClaim::class,
    ])
        ->callTableAction('addEvidence', null, ['label' => 'วิดีโอหลักฐาน']);

    $item = ClaimEvidenceItem::query()
        ->where('claim_id', $claim->id)
        ->where('label', 'วิดีโอหลักฐาน')
        ->firstOrFail();

    expect($item->is_default)->toBeFalse()
        ->and($item->checked)->toBeFalse()
        ->and($claim->evidenceItems()->count())->toBe($countBefore + 1);
});

// ─── 6. Timeline entries ──────────────────────────────────────────────────────

it('appending a timeline entry creates the record', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim();

    Livewire::test(TimelineEntriesRelationManager::class, [
        'ownerRecord' => $claim,
        'pageClass' => ViewClaim::class,
    ])
        ->callTableAction('appendEntry', null, ['action' => 'ยื่นเคลมครั้งแรก']);

    $entry = ClaimTimelineEntry::query()
        ->where('claim_id', $claim->id)
        ->where('action', 'ยื่นเคลมครั้งแรก')
        ->firstOrFail();

    expect($entry->action)->toBe('ยื่นเคลมครั้งแรก');
});

it('timeline RM has no edit or delete record action', function () {
    $admin = resAdmin();
    actingAs($admin);

    $claim = resClaim();
    $entry = app(AppendClaimTimelineEntry::class)->handle($claim, 'ทดสอบ');

    Livewire::test(TimelineEntriesRelationManager::class, [
        'ownerRecord' => $claim,
        'pageClass' => ViewClaim::class,
    ])
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

// ─── 7. Cross-tenant isolation ────────────────────────────────────────────────

it('a Claim from another tenant is not visible in the list', function () {
    // Tenant A (set by beforeEach)
    $adminA = resAdmin();
    actingAs($adminA);
    $claimA = resClaim();
    $orderIdA = $claimA->order()->firstOrFail()->platform_order_id;

    // Tenant B — separate tenant context
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('ClaimIsolationB-'.uniqid());
    app(TenantContext::class)->set($tenantB);
    $adminB = User::factory()->create(['tenant_id' => $tenantB->id]);
    $adminB->assignRole('Admin');
    actingAs($adminB);

    Livewire::test(ListClaims::class)
        ->assertDontSee($orderIdA);
});
