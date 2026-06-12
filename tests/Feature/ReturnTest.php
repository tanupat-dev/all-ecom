<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Listings\CreateListing;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Returns\UpsertReturn;
use App\Actions\Shops\CreateShop;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Filament\Resources\Returns\ReturnResource;
use App\Imports\NormalizedOrder;
use App\Imports\NormalizedReturn;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderReturn;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * A completed marketplace order to attach Returns to — importShop() and
 * the import core come from the Phase-4 tests.
 */
function returnableOrder(Shop $shop): Order
{
    return app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-R1',
        status: OrderStatus::Completed,
        lines: [
            ['variant' => variantBySku('TS-RED-M'), 'qty' => 2, 'unit_price' => Money::fromBaht('159')],
            ['variant' => variantBySku('TS-RED-L'), 'qty' => 1, 'unit_price' => Money::fromBaht('199')],
        ],
    ));
}

/**
 * @param  list<array{order_line: OrderLine, qty: int}>|null  $lines
 */
function returnOf(Order $order, ReturnSubStatus $subStatus = ReturnSubStatus::AwaitingBuyerShipment, ?array $lines = null): NormalizedReturn
{
    $orderLine = $order->lines->firstOrFail();

    return new NormalizedReturn(
        platformReturnId: 'RET-001',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: $subStatus,
        lines: $lines ?? [['order_line' => $orderLine, 'qty' => 1]],
        returnReason: 'สินค้าไม่ตรงกับคำอธิบาย',
        buyerNote: 'ได้รับสีผิด',
        refundAmount: Money::fromBaht('159'),
        trackingNumber: 'RTH123',
        requestedAt: new DateTimeImmutable('2026-06-10 09:00:00'),
    );
}

it('creates a Return with its lines as a case attached to the Order — the Order stays สำเร็จ', function () {
    $shop = importShop();
    $order = returnableOrder($shop);

    $return = app(UpsertReturn::class)->handle($shop, returnOf($order, lines: [
        ['order_line' => $order->lines->firstOrFail(), 'qty' => 1],
    ]));

    expect($return->platform_return_id)->toBe('RET-001')
        ->and($return->return_type)->toBe(ReturnType::ReturnAndRefund)
        ->and($return->sub_status)->toBe(ReturnSubStatus::AwaitingBuyerShipment)
        ->and($return->refund_amount?->satang)->toBe(15900)
        ->and($return->lines)->toHaveCount(1)
        ->and($return->lines->first()?->qty)->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Completed);
});

it('re-imports the same platform_return_id as an update, never a duplicate', function () {
    $shop = importShop();
    $order = returnableOrder($shop);

    app(UpsertReturn::class)->handle($shop, returnOf($order));
    $return = app(UpsertReturn::class)->handle($shop, returnOf($order, ReturnSubStatus::InTransitBack));

    expect(OrderReturn::query()->count())->toBe(1)
        ->and($return->sub_status)->toBe(ReturnSubStatus::InTransitBack);
});

it('never reverts a terminal Return — รับของกลับแล้ว locks against re-import', function () {
    $shop = importShop();
    $order = returnableOrder($shop);

    $return = app(UpsertReturn::class)->handle($shop, returnOf($order, ReturnSubStatus::Received));
    app(UpsertReturn::class)->handle($shop, returnOf($order, ReturnSubStatus::AwaitingBuyerShipment));

    expect($return->refresh()->sub_status)->toBe(ReturnSubStatus::Received);
});

it('moves no stock on upsert — stock returns only via Inbound Scan', function () {
    $shop = importShop();
    $order = returnableOrder($shop);
    $before = StockMovement::query()->count();

    app(UpsertReturn::class)->handle($shop, returnOf($order));

    expect(StockMovement::query()->count())->toBe($before);
});

it('lets an Admin through the Returns screens and blocks a Cashier', function () {
    [, $admin] = tenantWithUser('Admin');
    actingAs($admin);
    get(ReturnResource::getUrl('index'))->assertOk();

    [, $cashier] = tenantWithUser('Cashier');
    actingAs($cashier);
    get(ReturnResource::getUrl('index'))->assertForbidden();
});

function harnessReturn(): OrderReturn
{
    // Harness tenants have no auto-provisioned default Location.
    $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
    $shop = app(CreateShop::class)->handle('ร้าน harness', Platform::Shopee, $location);
    $product = app(CreateProduct::class)->handle('แก้วน้ำ', [
        ['master_sku' => 'CUP-1', 'list_price' => Money::fromBaht('59')],
    ]);
    app(CreateListing::class)->handle($shop, $product);

    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-R1',
        status: OrderStatus::Completed,
        lines: [['variant' => $product->variants->firstOrFail(), 'qty' => 1, 'unit_price' => Money::fromBaht('59')]],
    ));

    return app(UpsertReturn::class)->handle($shop, returnOf($order));
}

it('passes the cross-tenant isolation harness (returns)', function () {
    assertTenantIsolation(fn (): OrderReturn => harnessReturn());
});

it('passes the cross-tenant isolation harness (return lines)', function () {
    assertTenantIsolation(fn (): Model => harnessReturn()->lines()->firstOrFail());
});
