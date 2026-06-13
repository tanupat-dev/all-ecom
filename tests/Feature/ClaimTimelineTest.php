<?php

use App\Actions\Claims\AppendClaimTimelineEntry;
use App\Actions\Claims\CreateClaim;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\ClaimType;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\Claim;
use App\Models\ClaimTimelineEntry;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('ClaimTimelineTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─── helpers ──────────────────────────────────────────────────────────────────

function timelineShop(): Shop
{
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);

    return app(CreateShop::class)->handle('Shopee', Platform::Shopee, $location);
}

function timelineOrder(): Order
{
    return Order::query()->create([
        'shop_id' => timelineShop()->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => 'CLM-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);
}

function timelineClaim(): Claim
{
    return app(CreateClaim::class)->handle(timelineOrder(), ClaimType::ShippingOvercharge);
}

// ─── append + ordering ──────────────────────────────────────────────────────────

it('appends entries and preserves insertion order', function () {
    $claim = timelineClaim();

    $first = app(AppendClaimTimelineEntry::class)->handle($claim, 'submitted', occurredAt: Carbon::parse('2026-06-01'));
    $second = app(AppendClaimTimelineEntry::class)->handle($claim, 'info_requested', occurredAt: Carbon::parse('2026-06-03'));
    $third = app(AppendClaimTimelineEntry::class)->handle($claim, 'approved', occurredAt: Carbon::parse('2026-06-05'));

    $ids = $claim->timelineEntries()->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([$first->id, $second->id, $third->id])
        ->and($first->claim_id)->toBe($claim->id)
        ->and($first->action)->toBe('submitted');
});

it('never mutates a prior entry when a later one is appended', function () {
    $claim = timelineClaim();

    $first = app(AppendClaimTimelineEntry::class)->handle(
        $claim,
        'submitted',
        note: 'first note',
        ticketNo: 'TKT-1',
        occurredAt: Carbon::parse('2026-06-01 09:00:00'),
    );

    $snapshot = $first->only(['action', 'note', 'ticket_no', 'occurred_at']);

    app(AppendClaimTimelineEntry::class)->handle($claim, 'approved', occurredAt: Carbon::parse('2026-06-05'));

    $reloaded = ClaimTimelineEntry::query()->findOrFail($first->id);

    expect($reloaded->action)->toBe($snapshot['action'])
        ->and($reloaded->note)->toBe('first note')
        ->and($reloaded->ticket_no)->toBe('TKT-1')
        ->and($reloaded->occurred_at->equalTo(Carbon::parse('2026-06-01 09:00:00')))->toBeTrue();
});

// ─── append-only ledger (no mutation path) ──────────────────────────────────────

it('rejects updating a timeline entry (immutable ledger)', function () {
    $claim = timelineClaim();
    $entry = app(AppendClaimTimelineEntry::class)->handle($claim, 'submitted');

    expect(fn () => $entry->update(['note' => 'tampered']))
        ->toThrow(LogicException::class);
});

it('rejects deleting a timeline entry (immutable ledger)', function () {
    $claim = timelineClaim();
    $entry = app(AppendClaimTimelineEntry::class)->handle($claim, 'submitted');

    expect(fn () => $entry->delete())
        ->toThrow(LogicException::class);
});

// ─── money: payout_amount round-trips as satang (ADR 0015) ──────────────────────

it('round-trips payout_amount as integer satang with no float drift', function () {
    $claim = timelineClaim();

    $entry = app(AppendClaimTimelineEntry::class)->handle(
        $claim,
        'approved',
        payoutAmount: Money::fromBaht('123.45'),
    );

    // Stored as raw integer satang.
    $raw = DB::table('claim_timeline_entries')->where('id', $entry->id)->value('payout_amount');
    expect($raw)->toBe(12345);

    // Reads back as an equal Money value object.
    $reloaded = ClaimTimelineEntry::query()->findOrFail($entry->id);
    expect($reloaded->payout_amount)->toBeInstanceOf(Money::class)
        ->and($reloaded->payout_amount?->equals(Money::fromBaht('123.45')))->toBeTrue()
        ->and($reloaded->payout_amount?->satang)->toBe(12345);
});

it('leaves payout_amount null for non-money entries', function () {
    $claim = timelineClaim();
    $entry = app(AppendClaimTimelineEntry::class)->handle($claim, 'submitted');

    expect($entry->payout_amount)->toBeNull()
        ->and(ClaimTimelineEntry::query()->findOrFail($entry->id)->payout_amount)->toBeNull();
});

// ─── RBAC (ADR 0012): append gated on claim.manage ──────────────────────────────

it('allows append (create) for a user with claim.manage and read for claim.view', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $manageRole = Role::findOrCreate('TimelineManager-'.uniqid(), 'web');
    $manageRole->syncPermissions(['claim.manage']);

    $manager = User::factory()->create(['tenant_id' => $tenant->id]);
    $manager->assignRole($manageRole);

    $viewRole = Role::findOrCreate('TimelineViewer-'.uniqid(), 'web');
    $viewRole->syncPermissions(['claim.view']);

    $viewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $viewer->assignRole($viewRole);

    // claim.manage gates appending (create); claim.view gates reading only.
    expect($manager->can('create', ClaimTimelineEntry::class))->toBeTrue()
        ->and($viewer->can('viewAny', ClaimTimelineEntry::class))->toBeTrue()
        ->and($viewer->can('create', ClaimTimelineEntry::class))->toBeFalse();
});

// ─── Cross-tenant isolation (ADR 0011) ──────────────────────────────────────────

it('passes the cross-tenant isolation harness', function () {
    assertTenantIsolation(function (): ClaimTimelineEntry {
        return app(AppendClaimTimelineEntry::class)->handle(timelineClaim(), 'submitted');
    });
});
