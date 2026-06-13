<?php

use App\Actions\Accounting\RecomputeDailyPnl;
use App\Actions\Accounting\UpsertAccountingCycle;
use App\Actions\Catalog\SetCostPrice;
use App\Actions\Pos\CheckoutPosSale;
use App\Actions\Pos\CloseShift;
use App\Actions\Pos\ComputePosOrderNet;
use App\Actions\Pos\RefundPosSale;
use App\Actions\Pos\ShiftCashOverShort;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\AccountingLineCategory;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Enums\TenderType;
use App\Jobs\RecomputeDailyPnlJob;
use App\Models\DailyPnlRollup;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('Admin');
    actingAs($admin);

    // A fixed sale moment at 04:00 UTC = 11:00 Asia/Bangkok, so the UTC date
    // and the Bangkok bucket date coincide (2026-06-13) — keeps the assertions
    // tz-stable while still exercising the Bangkok-day bucket rule.
    Carbon::setTestNow(Carbon::parse('2026-06-13 04:00:00'));

    // Dirty-marking jobs are captured, not run, so each test drives the
    // recompute explicitly and deterministically (the observer wiring is
    // asserted on its own).
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
    app(TenantContext::class)->forget();
});

function pnlMarketplaceShop(string $name = 'Shopee'): Shop
{
    return app(CreateShop::class)->handle($name, Platform::Shopee, Location::query()->firstOrFail());
}

function pnlMarketplaceOrder(Shop $shop, string $oid, Carbon $saleDate): Order
{
    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => $oid,
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
        'created_date' => $saleDate,
    ]);
}

/** A POS Variant priced + stocked with a Cost Price active since long ago. */
function pnlCostVariant(string $sku, string $priceBaht, string $costBaht): Variant
{
    $variant = posVariant($sku, $priceBaht);
    app(SetCostPrice::class)->handle($variant, Money::fromBaht($costBaht), Carbon::parse('2020-01-01'));

    return $variant;
}

it('the rollup equals an independent from-raw recomputation across a mixed day', function () {
    $date = now();

    // --- Marketplace shop: one Order with signed accounting lines + an Expense ---
    $mShop = pnlMarketplaceShop();
    $order = pnlMarketplaceOrder($mShop, 'SP-1', $date);
    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-06', [
        ['source_field' => 'ยอดขาย', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('100')],
        ['source_field' => 'คอม', 'category' => AccountingLineCategory::Commission, 'amount' => Money::fromBaht('-10')],
        ['source_field' => 'การตลาด', 'category' => AccountingLineCategory::MarketingFee, 'amount' => Money::fromBaht('-5')],
    ]);
    Expense::query()->create([
        'date' => $date->toDateString(),
        'category' => 'packaging',
        'amount' => Money::fromBaht('20'),
        'ref_order_id' => $order->id,
    ]);

    // --- POS shop: two sales, a full return of the second, and a closed shift ---
    $shift = openPosShift();
    $posShop = $shift->register()->firstOrFail()->shop()->firstOrFail();
    $vA = pnlCostVariant('PNL-A', '150', '60'); // price 150, cost 60
    $vB = pnlCostVariant('PNL-B', '100', '40'); // price 100, cost 40

    app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $vA, 'qty' => 2]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('300')]]); // rev 30000, cogs 12000

    $saleB = app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $vB, 'qty' => 1]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')]]); // rev 10000, cogs 4000

    app(RefundPosSale::class)->handle($saleB,
        [['line' => $saleB->lines->firstOrFail(), 'qty' => 1]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')]]); // rev -10000, cogs -4000

    app(CloseShift::class)->handle($shift, Money::fromBaht('1000'));

    app(RecomputeDailyPnl::class)->handle($mShop->id, $date);
    app(RecomputeDailyPnl::class)->handle($posShop->id, $date);

    $mRow = DailyPnlRollup::query()->where('shop_id', $mShop->id)->where('date', $date->toDateString())->firstOrFail();
    $pRow = DailyPnlRollup::query()->where('shop_id', $posShop->id)->where('date', $date->toDateString())->firstOrFail();

    // Marketplace side: Actual Net = 100 − 10 − 5 = 85 baht; per-category split.
    expect($mRow->marketplace_actual_net)->toBe(8500)
        ->and($mRow->fee_breakdown)->toEqual(['sale_income' => 10000, 'commission' => -1000, 'marketing_fee' => -500])
        ->and($mRow->expense_total)->toBe(2000) // 20 baht
        ->and($mRow->pos_net)->toBe(0);

    // POS side (from-raw): rev 30000+10000−10000; cogs 12000+4000−4000; net = rev−cogs.
    expect($pRow->pos_revenue)->toBe(30000)
        ->and($pRow->pos_cogs)->toBe(12000)
        ->and($pRow->pos_net)->toBe(18000)
        ->and($pRow->marketplace_actual_net)->toBe(0)
        ->and($pRow->cash_over_short)->toBe(app(ShiftCashOverShort::class)->handle($shift->refresh())->satang);

    // pos_net is exactly Σ ComputePosOrderNet over the day's POS Orders.
    $sumPosNet = Order::query()
        ->where('shop_id', $posShop->id)
        ->where('platform_type', PlatformType::Pos)
        ->get()
        ->sum(fn (Order $o) => app(ComputePosOrderNet::class)->handle($o)->satang);
    expect($pRow->pos_net)->toBe($sumPosNet);
});

it('excludes an uncosted POS Order from the totals and counts it, never crashing the rollup', function () {
    $date = now();
    $shift = openPosShift();
    $posShop = $shift->register()->firstOrFail()->shop()->firstOrFail();

    // A Variant with NO Cost Price at the sale date — ComputePosOrderNet
    // fail-louds on it, but the rollup (and the checkout that triggers it)
    // must survive: the order is excluded from the POS totals and counted.
    $uncosted = posVariant('PNL-NOCOST', '100');
    $costed = pnlCostVariant('PNL-OK', '150', '60'); // rev 15000, cogs 6000, net 9000

    app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $uncosted, 'qty' => 1]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')]]);
    app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $costed, 'qty' => 1]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('150')]]);

    app(RecomputeDailyPnl::class)->handle($posShop->id, $date);

    $row = DailyPnlRollup::query()->where('shop_id', $posShop->id)->where('date', $date->toDateString())->firstOrFail();

    // Only the costed order lands in the totals (revenue − cogs == net stays
    // exact); the uncosted one is counted so #72 can flag the gap.
    expect($row->pos_revenue)->toBe(15000)
        ->and($row->pos_cogs)->toBe(6000)
        ->and($row->pos_net)->toBe(9000)
        ->and($row->uncosted_pos_orders)->toBe(1);
});

it('does not double the rollup when the same accounting cycle is re-imported', function () {
    $date = now();
    $shop = pnlMarketplaceShop();
    $order = pnlMarketplaceOrder($shop, 'SP-RE', $date);
    $lines = [
        ['source_field' => 'ยอดขาย', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('100')],
        ['source_field' => 'คอม', 'category' => AccountingLineCategory::Commission, 'amount' => Money::fromBaht('-10')],
    ];

    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-A', $lines);
    app(RecomputeDailyPnl::class)->handle($shop->id, $date);

    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-A', $lines); // re-import same cycle
    app(RecomputeDailyPnl::class)->handle($shop->id, $date);

    $rows = DailyPnlRollup::query()->where('shop_id', $shop->id)->where('date', $date->toDateString())->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->firstOrFail()->marketplace_actual_net)->toBe(9000); // 100 − 10, never 18000
});

it('keeps each date bucket independent — a write on one day does not disturb another', function () {
    $shop = pnlMarketplaceShop();
    $d13 = Carbon::parse('2026-06-13 04:00:00');
    $d14 = Carbon::parse('2026-06-14 04:00:00');

    $o13 = pnlMarketplaceOrder($shop, 'SP-13', $d13);
    app(UpsertAccountingCycle::class)->handle($o13, 'C13',
        [['source_field' => 'x', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('100')]]);

    $o14 = pnlMarketplaceOrder($shop, 'SP-14', $d14);
    app(UpsertAccountingCycle::class)->handle($o14, 'C14',
        [['source_field' => 'x', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('50')]]);

    app(RecomputeDailyPnl::class)->handle($shop->id, $d13);
    app(RecomputeDailyPnl::class)->handle($shop->id, $d14);

    expect(DailyPnlRollup::query()->where('date', '2026-06-13')->firstOrFail()->marketplace_actual_net)->toBe(10000)
        ->and(DailyPnlRollup::query()->where('date', '2026-06-14')->firstOrFail()->marketplace_actual_net)->toBe(5000);
});

it('splits the marketplace fee_breakdown per category, summing to Actual Net', function () {
    $date = now();
    $shop = pnlMarketplaceShop();
    $order = pnlMarketplaceOrder($shop, 'SP-FB', $date);
    app(UpsertAccountingCycle::class)->handle($order, 'C', [
        ['source_field' => 'ขาย', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('200')],
        ['source_field' => 'คอม', 'category' => AccountingLineCategory::Commission, 'amount' => Money::fromBaht('-15')],
        ['source_field' => 'การตลาด', 'category' => AccountingLineCategory::MarketingFee, 'amount' => Money::fromBaht('-8')],
    ]);

    app(RecomputeDailyPnl::class)->handle($shop->id, $date);

    $row = DailyPnlRollup::query()->where('shop_id', $shop->id)->firstOrFail();

    expect($row->fee_breakdown)->toEqual(['sale_income' => 20000, 'commission' => -1500, 'marketing_fee' => -800])
        ->and($row->marketplace_actual_net)->toBe(20000 - 1500 - 800);
});

it('rebuilds the rollup with pnl:rebuild after the table is truncated', function () {
    $date = now();
    $shop = pnlMarketplaceShop();
    $order = pnlMarketplaceOrder($shop, 'SP-RB', $date);
    app(UpsertAccountingCycle::class)->handle($order, 'C',
        [['source_field' => 'x', 'category' => AccountingLineCategory::SaleIncome, 'amount' => Money::fromBaht('100')]]);
    app(RecomputeDailyPnl::class)->handle($shop->id, $date);

    $before = DailyPnlRollup::query()->where('shop_id', $shop->id)->firstOrFail()->marketplace_actual_net;

    DailyPnlRollup::query()->delete();
    expect(DailyPnlRollup::query()->count())->toBe(0);

    expect(Artisan::call('pnl:rebuild', ['from' => $date->toDateString(), 'to' => $date->toDateString()]))
        ->toBe(0);

    $row = DailyPnlRollup::query()->where('shop_id', $shop->id)->where('date', $date->toDateString())->firstOrFail();
    expect($row->marketplace_actual_net)->toBe($before)->toBe(10000);
});

it('marks the (shop, date) bucket dirty on a write, deduped by one stable key', function () {
    $shift = openPosShift();
    $posShop = $shift->register()->firstOrFail()->shop()->firstOrFail();
    $variant = pnlCostVariant('PNL-D', '100', '40');

    Queue::fake(); // reset — ignore the setup writes, watch only the checkout

    app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $variant, 'qty' => 1]],
        [
            ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('60')],
            ['tender' => TenderType::PromptpayQr, 'amount' => Money::fromBaht('40')],
        ]);

    $expectedKey = "{$shift->tenant_id}:{$posShop->id}:".now()->toDateString();

    Queue::assertPushed(RecomputeDailyPnlJob::class,
        fn (RecomputeDailyPnlJob $job) => $job->uniqueId() === $expectedKey);

    // Two Payment writes in the same bucket collapse to one dedup key
    // (ShouldBeUnique), so an import chunk enqueues the recompute once.
    $keys = [];
    Queue::assertPushed(RecomputeDailyPnlJob::class, function (RecomputeDailyPnlJob $job) use (&$keys): bool {
        $keys[] = $job->uniqueId();

        return true;
    });
    expect(array_unique($keys))->toHaveCount(1);
});

it('passes the cross-tenant isolation harness (daily pnl rollups)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): DailyPnlRollup {
        $sequence++;
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle("Shopee-{$sequence}", Platform::Shopee, $location);

        return DailyPnlRollup::query()->create([
            'shop_id' => $shop->id,
            'date' => '2026-06-13',
            'marketplace_actual_net' => 1000,
            'fee_breakdown' => ['sale_income' => 1000],
            'pos_revenue' => 0,
            'pos_cogs' => 0,
            'pos_net' => 0,
            'expense_total' => 0,
            'cash_over_short' => 0,
        ]);
    });
});
