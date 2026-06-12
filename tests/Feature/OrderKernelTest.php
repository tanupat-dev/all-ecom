<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Orders\ApplyOrderMilestones;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\SetOrderStatus;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shop;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function posShop(): Shop
{
    return app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, Location::query()->firstOrFail());
}

function socialShop(): Shop
{
    return app(CreateShop::class)->handle('LINE', Platform::Line, Location::query()->firstOrFail());
}

function orderVariant(string $sku = 'ORD-1'): Variant
{
    return app(CreateProduct::class)
        ->handle("สินค้า {$sku}", [['master_sku' => $sku, 'list_price' => Money::fromBaht('100')]])
        ->variants->firstOrFail();
}

it('creates a manual order with lines and exact satang totals', function () {
    $shop = socialShop();
    $a = orderVariant('ORD-1');
    $b = orderVariant('ORD-2');

    $order = app(CreateOrder::class)->handle($shop, [
        ['variant' => $a, 'qty' => 2, 'unit_price' => Money::fromBaht('199.50')],
        ['variant' => $b, 'qty' => 1, 'unit_price' => Money::fromBaht('59.25')],
    ], buyerName: 'คุณลูกค้า');

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->platform_type)->toBe(PlatformType::Social)
        ->and($order->lines)->toHaveCount(2)
        ->and($order->lines->first()?->line_total?->satang)->toBe(39900)
        ->and($order->total?->satang)->toBe(45825)
        ->and($order->created_date)->not->toBeNull();
});

it('refuses creating a marketplace order manually — they are read-only mirrors', function () {
    $shop = app(CreateShop::class)->handle('Shopee', Platform::Shopee, Location::query()->firstOrFail());

    app(CreateOrder::class)->handle($shop, [
        ['variant' => orderVariant(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
    ]);
})->throws(LogicException::class, 'read-only mirrors');

it('refuses an order with no lines', function () {
    app(CreateOrder::class)->handle(socialShop(), []);
})->throws(InvalidArgumentException::class, 'at least one Order Line');

it('limits a pos order to its instant lifecycle', function () {
    $order = app(CreateOrder::class)->handle(posShop(), [
        ['variant' => orderVariant(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
    ]);

    app(SetOrderStatus::class)->handle($order, OrderStatus::Completed);

    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
});

it('refuses a pos order entering a fulfilment state it never has', function () {
    $order = app(CreateOrder::class)->handle(posShop(), [
        ['variant' => orderVariant(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
    ]);

    app(SetOrderStatus::class)->handle($order, OrderStatus::AwaitingPack);
})->throws(InvalidArgumentException::class, 'pos');

it('upserts milestones defensively — a missing value never erases an earlier one', function () {
    $order = app(CreateOrder::class)->handle(socialShop(), [
        ['variant' => orderVariant(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
    ]);

    app(ApplyOrderMilestones::class)->handle($order, [
        'paid_date' => Carbon::parse('2026-06-01 10:00'),
        'shipped_date' => Carbon::parse('2026-06-02 09:00'),
    ]);

    app(ApplyOrderMilestones::class)->handle($order, [
        'paid_date' => null, // a later snapshot omitting the column
        'delivered_date' => Carbon::parse('2026-06-04 14:00'),
    ]);

    $order->refresh();

    expect($order->paid_date?->toDateTimeString())->toBe('2026-06-01 10:00:00')
        ->and($order->shipped_date?->toDateTimeString())->toBe('2026-06-02 09:00:00')
        ->and($order->delivered_date?->toDateTimeString())->toBe('2026-06-04 14:00:00');
});

it('rejects an unknown milestone field, fail-loud', function () {
    $order = app(CreateOrder::class)->handle(socialShop(), [
        ['variant' => orderVariant(), 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
    ]);

    app(ApplyOrderMilestones::class)->handle($order, ['refunded_date' => now()]);
})->throws(InvalidArgumentException::class, 'not a milestone');

it('passes the cross-tenant isolation harness (orders)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): Order {
        $sequence++;
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle("LINE-{$sequence}", Platform::Line, $location);

        return app(CreateOrder::class)->handle($shop, [
            ['variant' => orderVariant("ORD-H-{$sequence}"), 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
        ]);
    });
});

it('passes the cross-tenant isolation harness (order lines)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): OrderLine {
        $sequence++;
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle("LINE-{$sequence}", Platform::Line, $location);
        $order = app(CreateOrder::class)->handle($shop, [
            ['variant' => orderVariant("OL-H-{$sequence}"), 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
        ]);

        return $order->lines->firstOrFail();
    });
});
