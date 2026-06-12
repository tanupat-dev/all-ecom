<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\DefineBundle;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\EditOrderLines;
use App\Actions\Orders\ReleaseOrderStock;
use App\Actions\Orders\ReserveOrderStock;
use App\Actions\Orders\SetOrderStatus;
use App\Actions\Orders\ShipOrderStock;
use App\Actions\Shops\CreateShop;
use App\Actions\Stock\AppendStockMovement;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Enums\StockAction;
use App\Models\Location;
use App\Models\Order;
use App\Models\Shop;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Variant;
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

function hookShop(Platform $platform = Platform::Line): Shop
{
    return app(CreateShop::class)->handle('ร้าน', $platform, Location::query()->firstOrFail());
}

function hookVariant(string $sku, int $onHand = 0): Variant
{
    $variant = app(CreateProduct::class)
        ->handle("สินค้า {$sku}", [['master_sku' => $sku, 'list_price' => Money::fromBaht('100')]])
        ->variants->firstOrFail();

    if ($onHand > 0) {
        app(AppendStockMovement::class)->handle($variant, Location::query()->firstOrFail(), StockAction::Receive, $onHand);
    }

    return $variant;
}

/**
 * @return array{int, int} [on_hand, reserved]
 */
function pools(Variant $variant): array
{
    $balance = StockBalance::query()->where('variant_id', $variant->id)->first();

    return [$balance->on_hand ?? 0, $balance->reserved ?? 0];
}

/**
 * @param  list<array{Variant, int}>  $variantQtys
 */
function orderOf(Shop $shop, array $variantQtys): Order
{
    $lines = [];
    foreach ($variantQtys as [$variant, $qty]) {
        $lines[] = ['variant' => $variant, 'qty' => $qty, 'unit_price' => Money::fromBaht('100')];
    }

    return app(CreateOrder::class)->handle($shop, $lines);
}

it('RESERVE fires per line at the shop fulfilment Location with the order as ref', function () {
    $a = hookVariant('HK-1', 10);
    $b = hookVariant('HK-2', 10);
    $order = orderOf(hookShop(), [[$a, 2], [$b, 3]]);

    app(ReserveOrderStock::class)->handle($order);

    expect(pools($a))->toBe([10, 2])
        ->and(pools($b))->toBe([10, 3])
        ->and(StockMovement::query()->where('ref_type', $order->getMorphClass())->where('ref_id', $order->id)->count())->toBe(2);
});

it('RELEASE compensates a cancelled pre-pack order', function () {
    $a = hookVariant('HK-1', 10);
    $order = orderOf(hookShop(), [[$a, 4]]);

    app(ReserveOrderStock::class)->handle($order);
    app(ReleaseOrderStock::class)->handle($order);

    expect(pools($a))->toBe([10, 0]);
});

it('SHIP for a reserving channel cuts On-Hand and the reservation it held', function () {
    $a = hookVariant('HK-1', 10);
    $order = orderOf(hookShop(), [[$a, 4]]);

    app(ReserveOrderStock::class)->handle($order);
    app(ShipOrderStock::class)->handle($order);

    expect(pools($a))->toBe([6, 0]);
});

it('SHIP for a pos order is a single immediate deduction — Reserved untouched', function () {
    $a = hookVariant('HK-1', 10);
    app(AppendStockMovement::class)->handle($a, Location::query()->firstOrFail(), StockAction::Reserve, 3); // someone else's reservation
    $order = orderOf(hookShop(Platform::Pos), [[$a, 2]]);

    app(ShipOrderStock::class)->handle($order);

    expect(pools($a))->toBe([8, 3]);
});

it('a bundle line expands into component movements through the order', function () {
    $soap = hookVariant('SOAP', 10);
    $bundle = hookVariant('SET-1');
    app(DefineBundle::class)->handle($bundle, [[$soap, 2]]);
    $order = orderOf(hookShop(), [[$bundle, 3]]);

    app(ReserveOrderStock::class)->handle($order);

    expect(pools($soap))->toBe([10, 6])
        ->and(pools($bundle))->toBe([0, 0]);
});

it('a pre-pack edit appends compensating movements', function () {
    $a = hookVariant('HK-1', 10);
    $b = hookVariant('HK-2', 10);
    $order = orderOf(hookShop(), [[$a, 2]]);
    app(ReserveOrderStock::class)->handle($order);
    app(SetOrderStatus::class)->handle($order, OrderStatus::AwaitingPack);

    // +1 qty of A, swap in B for a new line
    app(EditOrderLines::class)->handle($order, [
        ['variant' => $a, 'qty' => 3, 'unit_price' => Money::fromBaht('100')],
        ['variant' => $b, 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
    ]);

    expect(pools($a))->toBe([10, 3])
        ->and(pools($b))->toBe([10, 1])
        ->and($order->refresh()->total?->satang)->toBe(40000);
});

it('a pre-pack edit that removes a line releases its reservation', function () {
    $a = hookVariant('HK-1', 10);
    $b = hookVariant('HK-2', 10);
    $order = orderOf(hookShop(), [[$a, 2], [$b, 1]]);
    app(ReserveOrderStock::class)->handle($order);
    app(SetOrderStatus::class)->handle($order, OrderStatus::AwaitingPack);

    app(EditOrderLines::class)->handle($order, [
        ['variant' => $a, 'qty' => 2, 'unit_price' => Money::fromBaht('100')],
    ]);

    expect(pools($b))->toBe([10, 0])
        ->and($order->refresh()->lines)->toHaveCount(1);
});

it('refuses editing once tracking exists — post-pack lines are locked', function () {
    $a = hookVariant('HK-1', 10);
    $order = orderOf(hookShop(), [[$a, 2]]);
    $order->update(['tracking_number' => 'TH123456']);

    app(EditOrderLines::class)->handle($order, [
        ['variant' => $a, 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
    ]);
})->throws(LogicException::class, 'Post-pack');

it('refuses editing a marketplace order — read-only mirrors', function () {
    $a = hookVariant('HK-1', 10);
    $order = orderOf(hookShop(), [[$a, 2]]);
    $order->update(['platform_type' => PlatformType::Marketplace]);

    app(EditOrderLines::class)->handle($order, [
        ['variant' => $a, 'qty' => 1, 'unit_price' => Money::fromBaht('100')],
    ]);
})->throws(LogicException::class, 'read-only mirrors');
