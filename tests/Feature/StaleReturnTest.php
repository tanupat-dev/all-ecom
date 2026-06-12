<?php

use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Returns\UpsertReturn;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Imports\NormalizedOrder;
use App\Imports\NormalizedReturn;
use App\Models\OrderReturn;
use App\Models\Shop;
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

/** tiktokShop()/shopeeShop() come from the order-import tests. */
function staleCase(Shop $shop, string $id, DateTimeImmutable $requestedAt, ReturnSubStatus $subStatus = ReturnSubStatus::AwaitingBuyerShipment): OrderReturn
{
    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: "ORD-{$id}",
        status: OrderStatus::Completed,
        lines: [['variant' => variantBySku('TS-RED-M'), 'qty' => 1, 'unit_price' => Money::fromBaht('199')]],
    ));

    return app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: $id,
        returnType: ReturnType::ReturnAndRefund,
        subStatus: $subStatus,
        lines: [['order_line' => $order->lines->firstOrFail(), 'qty' => 1]],
        requestedAt: $requestedAt,
    ));
}

it('flags a TikTok Return stuck in รอผู้ซื้อส่งคืน past the 5-day buyer-ship window', function () {
    $shop = tiktokShop();
    $stale = staleCase($shop, 'TTR-S1', new DateTimeImmutable('-6 days'));
    $fresh = staleCase($shop, 'TTR-S2', new DateTimeImmutable('-2 days'));

    expect(OrderReturn::query()->stale()->pluck('platform_return_id')->all())->toBe(['TTR-S1'])
        ->and($stale->isStale())->toBeTrue()
        ->and($fresh->isStale())->toBeFalse();
});

it('never flags a platform whose buyer-ship window is undocumented', function () {
    $shop = shopeeShop();
    $old = staleCase($shop, 'SPR-S1', new DateTimeImmutable('-30 days'));

    expect(OrderReturn::query()->stale()->count())->toBe(0)
        ->and($old->isStale())->toBeFalse();
});

it('clears the flag once a re-import moves the Return on', function () {
    $shop = tiktokShop();
    staleCase($shop, 'TTR-S3', new DateTimeImmutable('-10 days'), ReturnSubStatus::Closed);

    expect(OrderReturn::query()->stale()->count())->toBe(0);
});
