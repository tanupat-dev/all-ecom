<?php

use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('ExpenseTenant');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ─── helpers ──────────────────────────────────────────────────────────────────

function expenseOrder(): Order
{
    $location = Location::query()->firstOrFail();
    $shop = app(CreateShop::class)->handle('Shopee', Platform::Shopee, $location);

    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => 'EXP-'.uniqid(),
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);
}

// ─── CRUD round-trip ──────────────────────────────────────────────────────────

it('creates an expense with a satang amount and persists it', function () {
    $expense = Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'packaging',
        'amount' => Money::fromBaht('250.50'),
        'note' => 'กล่องส่งพัสดุ',
    ]);

    expect($expense->id)->not->toBeNull()
        ->and($expense->category)->toBe('packaging')
        ->and($expense->amount)->toBeInstanceOf(Money::class)
        ->and($expense->amount->satang)->toBe(25050) // 250.50 baht = 25050 satang
        ->and($expense->note)->toBe('กล่องส่งพัสดุ');
});

it('stores amount as integer satang, never float', function () {
    $expense = Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'rent',
        'amount' => Money::fromBaht('1500.00'),
    ]);

    // Raw DB value must be an integer (satang), not a float (ADR 0015).
    $raw = DB::table('expenses')
        ->where('id', $expense->id)
        ->value('amount');

    expect($raw)->toBe(150000) // 1500 baht = 150000 satang
        ->and(is_int($raw))->toBeTrue();
});

it('updates an expense', function () {
    $expense = Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'packaging',
        'amount' => Money::fromBaht('100'),
    ]);

    $expense->update([
        'category' => 'staff',
        'amount' => Money::fromBaht('500'),
    ]);

    $expense->refresh();

    expect($expense->category)->toBe('staff')
        ->and($expense->amount->satang)->toBe(50000);
});

it('deletes an expense', function () {
    $expense = Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'packaging',
        'amount' => Money::fromBaht('100'),
    ]);

    $id = $expense->id;
    $expense->delete();

    expect(Expense::query()->find($id))->toBeNull();
});

// ─── ref_order_id (nullable) ──────────────────────────────────────────────────

it('allows null ref_order_id (non-attributable expense)', function () {
    $expense = Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'rent',
        'amount' => Money::fromBaht('3000'),
        'ref_order_id' => null,
    ]);

    expect($expense->ref_order_id)->toBeNull()
        ->and($expense->refOrder)->toBeNull();
});

it('links to an Order via ref_order_id', function () {
    $order = expenseOrder();

    $expense = Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'free gift',
        'amount' => Money::fromBaht('50'),
        'ref_order_id' => $order->id,
    ]);

    expect($expense->ref_order_id)->toBe($order->id)
        ->and($expense->refOrder()->firstOrFail()->id)->toBe($order->id);
});

// ─── category is free-form string, not an enum ───────────────────────────────

it('accepts any free-form category string', function () {
    $categories = ['packaging', 'rent', 'staff', 'utilities', 'กล่อง+ป้าย', 'บับเบิ้ลแรป'];

    foreach ($categories as $category) {
        $expense = Expense::query()->create([
            'date' => '2026-06-01',
            'category' => $category,
            'amount' => Money::fromBaht('10'),
        ]);

        expect($expense->category)->toBe($category);
    }
});

// ─── Filament resource smoke test ─────────────────────────────────────────────

it('renders the Expense list page for an Admin', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('Admin');

    actingAs($admin);

    get(ExpenseResource::getUrl('index'))->assertOk();
});

// ─── Permission gates (ADR 0012) ──────────────────────────────────────────────

it('allows listing for a user with accounting.view', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $viewRole = Role::findOrCreate('AccountingViewer-'.uniqid(), 'web');
    $viewRole->syncPermissions(['accounting.view']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($viewRole);

    expect($user->can('viewAny', Expense::class))->toBeTrue();
});

it('denies create/update/delete for a user with only accounting.view', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $viewRole = Role::findOrCreate('ViewOnlyAccounting-'.uniqid(), 'web');
    $viewRole->syncPermissions(['accounting.view']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($viewRole);

    $expense = Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'packaging',
        'amount' => Money::fromBaht('100'),
    ]);

    expect($user->can('create', Expense::class))->toBeFalse()
        ->and($user->can('update', $expense))->toBeFalse()
        ->and($user->can('delete', $expense))->toBeFalse();
});

it('allows create/update/delete for a user with accounting.manage', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $manageRole = Role::findOrCreate('AccountingManager-'.uniqid(), 'web');
    $manageRole->syncPermissions(['accounting.manage']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($manageRole);

    $expense = Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'packaging',
        'amount' => Money::fromBaht('100'),
    ]);

    expect($user->can('create', Expense::class))->toBeTrue()
        ->and($user->can('update', $expense))->toBeTrue()
        ->and($user->can('delete', $expense))->toBeTrue();
});

it('blocks the Expense list page for a Cashier (no accounting.view)', function () {
    $tenant = app(TenantContext::class)->current();
    assert($tenant !== null);

    $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
    $cashier->assignRole('Cashier');

    actingAs($cashier);

    get(ExpenseResource::getUrl('index'))->assertForbidden();
});

// ─── Cross-tenant isolation (ADR 0011) ────────────────────────────────────────

it('passes the cross-tenant isolation harness', function () {
    assertTenantIsolation(fn (): Expense => Expense::query()->create([
        'date' => '2026-06-01',
        'category' => 'packaging',
        'amount' => Money::fromBaht('100'),
    ]));
});
