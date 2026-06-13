<?php

use App\Actions\Reporting\ComputeCombinedPnl;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\Platform;
use App\Filament\Pages\CombinedPnl;
use App\Models\DailyPnlRollup;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);

    Carbon::setTestNow(Carbon::parse('2026-06-15 04:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
    app(TenantContext::class)->forget();
});

// ─── helpers ───────────────────────────────────────────────────────────────

function pnlShop(string $name, Platform $platform = Platform::Shopee): Shop
{
    return app(CreateShop::class)->handle($name, $platform, Location::query()->firstOrFail());
}

/**
 * Create a DailyPnlRollup row for the given shop and date with explicit data.
 *
 * @param  array<string, int>  $feeBreakdown
 */
function pnlRollupRow(
    Shop $shop,
    string $date,
    int $marketplaceActualNet = 0,
    array $feeBreakdown = [],
    int $posRevenue = 0,
    int $posCogs = 0,
    int $posNet = 0,
    int $uncostedPosOrders = 0,
    int $cashOverShort = 0,
): DailyPnlRollup {
    return DailyPnlRollup::query()->create([
        'shop_id' => $shop->id,
        'date' => $date,
        'marketplace_actual_net' => $marketplaceActualNet,
        'fee_breakdown' => $feeBreakdown,
        'pos_revenue' => $posRevenue,
        'pos_cogs' => $posCogs,
        'pos_net' => $posNet,
        'uncosted_pos_orders' => $uncostedPosOrders,
        'expense_total' => 0,
        'cash_over_short' => $cashOverShort,
    ]);
}

// ─── unit: ComputeCombinedPnl action ───────────────────────────────────────

it('aggregates marketplace_net from two marketplace shops', function () {
    $shopeeShop = pnlShop('Shopee1', Platform::Shopee);
    $lazadaShop = pnlShop('Lazada1', Platform::Lazada);

    pnlRollupRow($shopeeShop, '2026-06-01', marketplaceActualNet: 8500, feeBreakdown: ['sale_income' => 10000, 'commission' => -1500]);
    pnlRollupRow($lazadaShop, '2026-06-01', marketplaceActualNet: 5000, feeBreakdown: ['sale_income' => 6000, 'commission' => -1000]);

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: true,
    );

    expect($result['marketplace_net'])->toBe(13500) // 8500 + 5000
        ->and($result['fee_breakdown']['sale_income'])->toBe(16000) // 10000 + 6000
        ->and($result['fee_breakdown']['commission'])->toBe(-2500); // -1500 + -1000
});

it('aggregates pos totals from a POS shop', function () {
    $posShop = pnlShop('หน้าร้าน', Platform::Pos);

    pnlRollupRow($posShop, '2026-06-01',
        posRevenue: 30000,
        posCogs: 12000,
        posNet: 18000,
    );
    pnlRollupRow($posShop, '2026-06-02',
        posRevenue: 15000,
        posCogs: 6000,
        posNet: 9000,
    );

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: true,
    );

    expect($result['pos_revenue'])->toBe(45000)
        ->and($result['pos_cogs'])->toBe(18000)
        ->and($result['pos_net'])->toBe(27000);
});

it('excludes rollup rows outside the date range', function () {
    $shop = pnlShop('Shopee1');

    pnlRollupRow($shop, '2026-05-31', marketplaceActualNet: 99999); // before range — excluded
    pnlRollupRow($shop, '2026-06-01', marketplaceActualNet: 8000);  // in range
    pnlRollupRow($shop, '2026-06-30', marketplaceActualNet: 2000);  // in range
    pnlRollupRow($shop, '2026-07-01', marketplaceActualNet: 99999); // after range — excluded

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: true,
    );

    expect($result['marketplace_net'])->toBe(10000); // 8000 + 2000 only
});

it('operating expenses line equals full tenant expense sum including non-attributable', function () {
    // Non-attributable expense (no ref_order_id) — most opex lives here
    Expense::query()->create([
        'date' => '2026-06-10',
        'category' => 'ค่าเช่า',
        'amount' => Money::fromBaht('500'),
    ]);
    // Per-order attributable expense
    Expense::query()->create([
        'date' => '2026-06-12',
        'category' => 'กล่อง',
        'amount' => Money::fromBaht('20'),
    ]);

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: true,
    );

    // Both expenses in period: 500 + 20 = 520 baht = 52000 satang
    expect($result['operating_expenses'])->toBe(52000);
});

it('expenses outside the period are excluded', function () {
    Expense::query()->create([
        'date' => '2026-05-31',
        'category' => 'ค่าเช่า',
        'amount' => Money::fromBaht('500'),
    ]);
    Expense::query()->create([
        'date' => '2026-06-10',
        'category' => 'กล่อง',
        'amount' => Money::fromBaht('20'),
    ]);

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: true,
    );

    expect($result['operating_expenses'])->toBe(2000); // only the June expense
});

it('cash over/short is summed with correct sign across shops', function () {
    $posShop = pnlShop('POS', Platform::Pos);
    $shopeeShop = pnlShop('Shopee', Platform::Shopee);

    pnlRollupRow($posShop, '2026-06-01', cashOverShort: -300); // shortage
    pnlRollupRow($posShop, '2026-06-02', cashOverShort: 100);  // overage
    pnlRollupRow($shopeeShop, '2026-06-01', cashOverShort: 0);

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: true,
    );

    expect($result['cash_over_short'])->toBe(-200); // -300 + 100
});

it('combined_net = marketplace_net + pos_net - operating_expenses + cash_over_short', function () {
    $shopeeShop = pnlShop('Shopee', Platform::Shopee);
    $posShop = pnlShop('POS', Platform::Pos);

    pnlRollupRow($shopeeShop, '2026-06-01', marketplaceActualNet: 8500, feeBreakdown: ['sale_income' => 10000, 'commission' => -1500]);
    pnlRollupRow($posShop, '2026-06-01', posRevenue: 30000, posCogs: 12000, posNet: 18000, cashOverShort: -200);

    Expense::query()->create(['date' => '2026-06-10', 'category' => 'ค่าเช่า', 'amount' => Money::fromBaht('50')]);

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: true,
    );

    // 8500 + 18000 - 5000 + (-200) = 21300
    expect($result['combined_net'])->toBe(8500 + 18000 - 5000 + (-200))
        ->toBe(21300);
});

it('uncosted_pos_orders is summed across all shops and days', function () {
    $posShop = pnlShop('POS', Platform::Pos);

    pnlRollupRow($posShop, '2026-06-01', uncostedPosOrders: 1);
    pnlRollupRow($posShop, '2026-06-02', uncostedPosOrders: 3);

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: true,
    );

    expect($result['uncosted_pos_orders'])->toBe(4);
});

// ─── cost.view gate (RBAC) — action layer ──────────────────────────────────

it('pos_cogs, pos_net, and combined_net are null when canViewCost is false', function () {
    $posShop = pnlShop('POS', Platform::Pos);
    pnlRollupRow($posShop, '2026-06-01', posRevenue: 30000, posCogs: 12000, posNet: 18000);

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: false,
    );

    expect($result['pos_cogs'])->toBeNull()
        ->and($result['pos_net'])->toBeNull()
        ->and($result['combined_net'])->toBeNull()
        ->and($result['pos_revenue'])->toBe(30000); // revenue still visible
});

it('pos_revenue and marketplace_net are visible even without cost.view', function () {
    $shopeeShop = pnlShop('Shopee', Platform::Shopee);
    $posShop = pnlShop('POS', Platform::Pos);

    pnlRollupRow($shopeeShop, '2026-06-01', marketplaceActualNet: 8500, feeBreakdown: ['sale_income' => 10000, 'commission' => -1500]);
    pnlRollupRow($posShop, '2026-06-01', posRevenue: 30000, posNet: 18000);

    $result = app(ComputeCombinedPnl::class)->handle(
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        canViewCost: false,
    );

    expect($result['marketplace_net'])->toBe(8500)
        ->and($result['pos_revenue'])->toBe(30000)
        ->and($result['can_view_cost'])->toBeFalse();
});

// ─── Filament page: rendering ──────────────────────────────────────────────

it('renders marketplace net in baht (satang → baht at view edge, no float)', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $shopeeShop = pnlShop('Shopee1', Platform::Shopee);
    // 8500 satang = 85.00 baht
    pnlRollupRow($shopeeShop, '2026-06-01', marketplaceActualNet: 8500, feeBreakdown: ['sale_income' => 10000, 'commission' => -1500]);

    Livewire::test(CombinedPnl::class)
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        ->assertSee('85.00')      // marketplace_net
        ->assertSee('100.00')     // sale_income
        ->assertSee('-15.00');    // commission
});

it('renders pos revenue in baht for an admin with cost.view', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $posShop = pnlShop('หน้าร้าน', Platform::Pos);
    // pos_revenue 30000 satang = 300.00 baht, pos_net 18000 = 180.00
    pnlRollupRow($posShop, '2026-06-01', posRevenue: 30000, posCogs: 12000, posNet: 18000);

    Livewire::test(CombinedPnl::class)
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        ->assertSee('300.00')   // pos_revenue
        ->assertSee('120.00')   // pos_cogs
        ->assertSee('180.00');  // pos_net
});

it('user with report.view only does not see COGS or net profit in rendered page', function () {
    [$tenant] = tenantWithUser('Admin');

    // Create a role with only report.view — no cost.view
    $reportOnly = Role::findOrCreate('report-only', 'web');
    $reportOnly->syncPermissions(['report.view']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($reportOnly);

    actingAs($user);

    $posShop = pnlShop('หน้าร้าน', Platform::Pos);
    pnlRollupRow($posShop, '2026-06-01', posRevenue: 30000, posCogs: 12000, posNet: 18000);

    // Also add marketplace so there's something to see
    $shopeeShop = pnlShop('Shopee1', Platform::Shopee);
    pnlRollupRow($shopeeShop, '2026-06-01', marketplaceActualNet: 8500);

    Livewire::test(CombinedPnl::class)
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        ->assertSee('300.00')            // pos_revenue IS visible
        ->assertSee('85.00')             // marketplace_net IS visible
        ->assertDontSee('COGS')          // pos_cogs label hidden
        ->assertDontSee('120.00')        // pos_cogs amount hidden
        ->assertDontSee('กำไร POS')      // pos_net label hidden
        ->assertDontSee('กำไรสุทธิรวม'); // combined_net label hidden
});

it('user with both report.view and cost.view sees the full P&L', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $posShop = pnlShop('หน้าร้าน', Platform::Pos);
    pnlRollupRow($posShop, '2026-06-01', posRevenue: 30000, posCogs: 12000, posNet: 18000);

    Livewire::test(CombinedPnl::class)
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        ->assertSee('COGS')
        ->assertSee('120.00')           // pos_cogs
        ->assertSee('180.00')           // pos_net
        ->assertSee('กำไรสุทธิรวม');   // combined_net label
});

it('shows the incompleteness notice when uncosted_pos_orders > 0', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $posShop = pnlShop('หน้าร้าน', Platform::Pos);
    pnlRollupRow($posShop, '2026-06-01', posRevenue: 10000, posNet: 8000, uncostedPosOrders: 2);

    Livewire::test(CombinedPnl::class)
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        // assert on text that has no HTML-encoding ambiguity (assertSee escapes & → &amp;)
        ->assertSee('ยังไม่สมบูรณ์')
        ->assertSee('2'); // count of uncosted orders
});

it('does not show the incompleteness notice when all POS orders are costed', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    $posShop = pnlShop('หน้าร้าน', Platform::Pos);
    pnlRollupRow($posShop, '2026-06-01', posRevenue: 10000, posNet: 8000, uncostedPosOrders: 0);

    Livewire::test(CombinedPnl::class)
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        ->assertDontSee('ยังไม่สมบูรณ์');
});

// ─── RBAC: page access ─────────────────────────────────────────────────────

it('returns 403 for a user without report.view', function () {
    [$tenant] = tenantWithUser('Admin');

    $blind = Role::findOrCreate('ไม่เห็นรายงาน', 'web');
    $blind->syncPermissions(['product.view']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($blind);

    actingAs($user);

    get(CombinedPnl::getUrl())->assertForbidden();
});

it('lets an admin (report.view) access the combined P&L page', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);

    get(CombinedPnl::getUrl())->assertOk();
});

// ─── cross-tenant isolation ────────────────────────────────────────────────

it('never shows another tenant\'s rollup data', function () {
    // Tenant A (current): some marketplace revenue
    [, $adminA] = tenantWithUser('Admin');
    actingAs($adminA);

    $shopA = pnlShop('Shopee-A', Platform::Shopee);
    pnlRollupRow($shopA, '2026-06-01', marketplaceActualNet: 50000); // 500.00 baht

    // Tenant B: different revenue that must NOT appear in A's view
    app(TenantContext::class)->forget();
    $tenantB = app(CreateTenant::class)->handle('B');
    app(TenantContext::class)->set($tenantB);

    $locationB = Location::query()->firstOrFail();
    $shopB = app(CreateShop::class)->handle('Shopee-B', Platform::Shopee, $locationB);
    DailyPnlRollup::query()->create([
        'shop_id' => $shopB->id,
        'date' => '2026-06-01',
        'marketplace_actual_net' => 99999, // 999.99 baht — must NOT appear for A
        'fee_breakdown' => [],
        'pos_revenue' => 0,
        'pos_cogs' => 0,
        'pos_net' => 0,
        'expense_total' => 0,
        'cash_over_short' => 0,
    ]);

    // Switch back to tenant A, assert B's data is invisible
    app(TenantContext::class)->set($adminA->tenant()->firstOrFail());
    actingAs($adminA);

    Livewire::test(CombinedPnl::class)
        ->set('dateFrom', '2026-06-01')
        ->set('dateTo', '2026-06-30')
        ->assertSee('500.00')      // A's data
        ->assertDontSee('999.99'); // B's data never appears
});
