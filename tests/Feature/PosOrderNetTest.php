<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\DefineBundle;
use App\Actions\Catalog\SetCostPrice;
use App\Actions\Pos\CheckoutPosSale;
use App\Actions\Pos\CloseShift;
use App\Actions\Pos\ComputePosOrderNet;
use App\Actions\Pos\RefundPosSale;
use App\Actions\Pos\ShiftCashOverShort;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\PlatformType;
use App\Enums\TenderType;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('Admin');
    actingAs($admin);
});

afterEach(function () {
    Carbon::setTestNow();
    app(TenantContext::class)->forget();
});

// openPosShift() / posVariant() live in tests/Helpers/PosHelpers.php.

/** A POS Variant priced + stocked, with a Cost Price active since long ago. */
function pricedCostVariant(string $sku, string $priceBaht, string $costBaht, int $onHand = 10): Variant
{
    $variant = posVariant($sku, $priceBaht, $onHand);
    app(SetCostPrice::class)->handle($variant, Money::fromBaht($costBaht), Carbon::parse('2020-01-01'));

    return $variant;
}

it('nets a POS sale: the Payment total net of change minus COGS, to the satang', function () {
    $shift = openPosShift();
    $variant = pricedCostVariant('PNL-1', '150', '60'); // list 150, cost 60

    $order = app(CheckoutPosSale::class)->handle($shift, [
        ['variant' => $variant, 'qty' => 2], // total 300
    ], [
        ['tender' => TenderType::PromptpayQr, 'amount' => Money::fromBaht('150')],
        ['tender' => TenderType::Cash, 'amount' => Money::fromBaht('200')], // tendered 350, change 50 cash
    ]);

    // revenue = 30000 (the 5000 change is handed back, never kept);
    // COGS = 6000 × 2 = 12000; net = 18000.
    expect(app(ComputePosOrderNet::class)->handle($order)->satang)->toBe(18000);
});

it('uses each sale-date Cost Price, not the current cost, across a cost change', function () {
    $shift = openPosShift();
    $variant = posVariant('PNL-H', '150');
    $set = app(SetCostPrice::class);
    $set->handle($variant, Money::fromBaht('40'), Carbon::parse('2026-01-01'));
    $set->handle($variant, Money::fromBaht('55'), Carbon::parse('2026-06-01'));

    Carbon::setTestNow('2026-03-15');
    $early = app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $variant, 'qty' => 1]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('150')]]);

    Carbon::setTestNow('2026-09-01');
    $late = app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $variant, 'qty' => 1]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('150')]]);

    Carbon::setTestNow();

    $net = app(ComputePosOrderNet::class);
    // early sold 2026-03-15 → cost 4000 → net 11000;
    // late  sold 2026-09-01 → cost 5500 → net  9500 (current cost is 5500, but early must NOT use it).
    expect($net->handle($early)->satang)->toBe(11000)
        ->and($net->handle($late)->satang)->toBe(9500);
});

it('takes a Bundle line COGS from its component costs at the sale date', function () {
    $shift = openPosShift();
    $bundle = app(CreateProduct::class)
        ->handle('ชุดเซ็ต', [['master_sku' => 'PNL-SET', 'list_price' => Money::fromBaht('100')]])
        ->variants->firstOrFail();
    $soap = pricedCostVariant('PNL-SOAP', '10', '10');     // cost 1000
    $towel = pricedCostVariant('PNL-TOWEL', '30', '25.50'); // cost 2550
    app(DefineBundle::class)->handle($bundle, [[$soap, 2], [$towel, 1]]);

    $order = app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $bundle, 'qty' => 1]], // total 10000
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('100')]]);

    // COGS = 2×1000 + 2550 = 4550; revenue 10000; net 5450.
    expect(app(ComputePosOrderNet::class)->handle($order)->satang)->toBe(5450);
});

it('yields a negative net for a POS Return — refund out minus goods back', function () {
    openPosShift();
    $variant = pricedCostVariant('PNL-R', '150', '60');
    $sale = app(CheckoutPosSale::class)->handle(Shift::query()->firstOrFail(),
        [['variant' => $variant, 'qty' => 2]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('300')]]);

    $refund = app(RefundPosSale::class)->handle($sale,
        [['line' => $sale->lines->firstOrFail(), 'qty' => 2]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('300')]]);

    // revenue −30000; COGS = 6000 × (−2) = −12000; net = −30000 − (−12000) = −18000.
    // The sale's +18000 and this full return's −18000 cancel — the P&L nets to zero.
    expect(app(ComputePosOrderNet::class)->handle($refund)->satang)->toBe(-18000);
});

it('fails loud when a sold Variant has no Cost Price at the sale date', function () {
    $shift = openPosShift();
    $variant = posVariant('PNL-NOCOST', '150'); // priced but no Cost Price
    $order = app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $variant, 'qty' => 1]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('150')]]);

    app(ComputePosOrderNet::class)->handle($order);
})->throws(LogicException::class, 'no Cost Price at the sale date');

it('refuses a non-POS Order — marketplace P&L comes from the Accounting Entry', function () {
    openPosShift();
    $shop = Shop::query()->firstOrFail();
    $order = Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Social,
        'status' => OrderStatus::Completed,
        'total' => Money::fromSatang(0),
        'created_date' => now(),
    ]);

    app(ComputePosOrderNet::class)->handle($order);
})->throws(InvalidArgumentException::class, 'only POS Orders');

it('proves a POS Order carries no Accounting Entry / Actual Net (the P&L path is fee-less)', function () {
    $shift = openPosShift();
    $variant = pricedCostVariant('PNL-FEELESS', '150', '60');
    $order = app(CheckoutPosSale::class)->handle($shift,
        [['variant' => $variant, 'qty' => 1]],
        [['tender' => TenderType::Cash, 'amount' => Money::fromBaht('150')]]);

    expect($order->accountingEntryLines()->count())->toBe(0)
        ->and($order->actual_net)->toBeNull()
        ->and($order->settlement_date)->toBeNull()
        ->and($order->return_sub_status)->toBeNull();
});

it('reports Cash Over/Short signed — a shortage is a negative other-expense', function () {
    $shift = openPosShift(); // opening float 1000, no sales → expected 1000
    app(CloseShift::class)->handle($shift, Money::fromBaht('950')); // counted short by 50

    expect(app(ShiftCashOverShort::class)->handle($shift->refresh())->satang)->toBe(-5000);
});

it('reports a Cash overage as a positive other-income', function () {
    $shift = openPosShift();
    app(CloseShift::class)->handle($shift, Money::fromBaht('1010')); // counted over by 10

    expect(app(ShiftCashOverShort::class)->handle($shift->refresh())->satang)->toBe(1000);
});

it('refuses to report Cash Over/Short on an open Shift — it is undefined until close', function () {
    $shift = openPosShift();
    app(ShiftCashOverShort::class)->handle($shift);
})->throws(LogicException::class, 'after the Shift is closed');
