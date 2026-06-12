<?php

use App\Actions\Listings\CreateListing;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Returns\RecordBouncedInboundScan;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\ReturnSubStatus;
use App\Imports\NormalizedOrder;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Shop;
use App\Models\StockBalance;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
    actingAs(adminUserForScan($tenant->id));
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** hookVariant()/pools()/importShop()/adminUserForScan() come from earlier test files. */
function bouncedOrder(Shop $shop, OrderStatus $status = OrderStatus::Bounced): Order
{
    $variant = hookVariant('BN-1', 10);
    app(CreateListing::class)->handle($shop, $variant->product()->firstOrFail());

    return app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-B1',
        status: $status,
        lines: [['variant' => $variant, 'qty' => 3, 'unit_price' => Money::fromBaht('159')]],
    ));
}

it('credits the whole package back on scan and locks the Order at รับของกลับแล้ว', function () {
    $shop = importShop();
    $order = bouncedOrder($shop);
    $variant = $order->lines->firstOrFail()->variant()->firstOrFail();

    // On-Hand 10 − 3 shipped (Bounced = post-ship) = 7; the scan credits all 3.
    app(RecordBouncedInboundScan::class)->handle($order);

    expect(pools($variant))->toBe([10, 0])
        ->and($order->refresh()->return_sub_status)->toBe(ReturnSubStatus::Received)
        ->and(AuditLog::query()->where('action', 'return.inbound_scan')->exists())->toBeTrue();
});

it('routes damaged units to the Damaged pool at scan', function () {
    $shop = importShop();
    $order = bouncedOrder($shop);
    $line = $order->lines->firstOrFail();

    app(RecordBouncedInboundScan::class)->handle($order, damagedOrderLineIds: [$line->id]);

    $balance = StockBalance::query()->where('variant_id', $line->variant_id)->firstOrFail();

    expect($balance->on_hand)->toBe(7)
        ->and($balance->damaged)->toBe(3);
});

it('refuses scanning an Order that is not ตีกลับ', function () {
    $shop = importShop();

    app(RecordBouncedInboundScan::class)->handle(bouncedOrder($shop, OrderStatus::Completed));
})->throws(LogicException::class, 'ตีกลับ');

it('refuses a second scan — stock never double-credits', function () {
    $shop = importShop();
    $order = bouncedOrder($shop);

    app(RecordBouncedInboundScan::class)->handle($order);
    app(RecordBouncedInboundScan::class)->handle($order->refresh());
})->throws(LogicException::class, 'รับของกลับแล้ว');

it('requires the return.manage permission', function () {
    $shop = importShop();
    $order = bouncedOrder($shop);

    $cashier = User::factory()->create(['tenant_id' => $order->tenant_id]);
    $cashier->assignRole('Cashier');
    actingAs($cashier);

    app(RecordBouncedInboundScan::class)->handle($order);
})->throws(AuthorizationException::class);
