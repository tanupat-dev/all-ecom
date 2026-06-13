<?php

use App\Actions\Accounting\UpsertAccountingCycle;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\FeeCategory;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\AccountingEntryLine;
use App\Models\Location;
use App\Models\Order;
use App\Support\Money;
use App\Tenancy\TenantContext;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function accountingOrder(string $platformOrderId = 'SP-1'): Order
{
    $shop = app(CreateShop::class)->handle('Shopee', Platform::Shopee, Location::query()->firstOrFail());

    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Marketplace,
        'platform_order_id' => $platformOrderId,
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);
}

function accountingPosOrder(): Order
{
    $shop = app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, Location::query()->firstOrFail());

    return Order::query()->create([
        'shop_id' => $shop->id,
        'platform_type' => PlatformType::Pos,
        'status' => OrderStatus::Completed,
        'total' => Money::fromBaht('100'),
    ]);
}

it('replaces the same cycle idempotently — re-importing never double-counts', function () {
    $order = accountingOrder();
    $lines = [
        ['source_field' => 'ยอดขายสินค้า', 'category' => FeeCategory::Other, 'amount' => Money::fromBaht('100')],
        ['source_field' => 'ค่าคอมมิชชั่น', 'category' => FeeCategory::Commission, 'amount' => Money::fromBaht('-10')],
    ];

    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', $lines);
    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', $lines);

    expect($order->accountingEntryLines()->count())->toBe(2)
        ->and($order->refresh()->actual_net?->satang)->toBe(9000);
});

it('appends a later cycle without touching the first cycle, summing Actual Net across both', function () {
    $order = accountingOrder();

    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', [
        ['source_field' => 'ยอดขายสินค้า', 'category' => FeeCategory::Other, 'amount' => Money::fromBaht('100')],
    ]);

    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-06', [
        ['source_field' => 'ค่าส่งคืนสินค้า', 'category' => FeeCategory::ShippingReturn, 'amount' => Money::fromBaht('-30')],
    ]);

    expect($order->accountingEntryLines()->count())->toBe(2)
        ->and($order->accountingEntryLines()->where('statement_cycle', 'CYCLE-2026-05')->count())->toBe(1)
        ->and($order->refresh()->actual_net?->satang)->toBe(7000);
});

it('lets a negative amount reduce Actual Net, exact in integer satang', function () {
    $order = accountingOrder();

    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', [
        ['source_field' => 'ยอดขายสินค้า', 'category' => FeeCategory::Other, 'amount' => Money::fromBaht('100')],
        ['source_field' => 'ค่าธรรมเนียมชำระเงิน', 'category' => FeeCategory::PaymentFee, 'amount' => Money::fromBaht('-25.50')],
    ]);

    $actualNet = $order->refresh()->actual_net;

    expect($actualNet)->toBeInstanceOf(Money::class)
        ->and($actualNet?->satang)->toBe(7450);
});

it('refuses a POS Order — its P&L is computed directly, never via accounting lines', function () {
    $order = accountingPosOrder();

    app(UpsertAccountingCycle::class)->handle($order, 'CYCLE-2026-05', [
        ['source_field' => 'x', 'category' => FeeCategory::Other, 'amount' => Money::fromBaht('100')],
    ]);
})->throws(InvalidArgumentException::class, 'A POS Order has no Accounting Entry');

it('passes the cross-tenant isolation harness (accounting entry lines)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): AccountingEntryLine {
        $sequence++;
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        $shop = app(CreateShop::class)->handle("Shopee-{$sequence}", Platform::Shopee, $location);
        $order = Order::query()->create([
            'shop_id' => $shop->id,
            'platform_type' => PlatformType::Marketplace,
            'platform_order_id' => "SP-H-{$sequence}",
            'status' => OrderStatus::Completed,
            'total' => Money::fromBaht('100'),
        ]);

        return AccountingEntryLine::query()->create([
            'order_id' => $order->id,
            'statement_cycle' => 'CYCLE-H',
            'source_field' => 'ยอดขายสินค้า',
            'category' => FeeCategory::Other,
            'amount' => Money::fromBaht('100'),
        ]);
    });
});
