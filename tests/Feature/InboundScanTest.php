<?php

use App\Actions\Catalog\DefineBundle;
use App\Actions\Listings\CreateListing;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Returns\RecordInboundScan;
use App\Actions\Returns\UpsertReturn;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Imports\NormalizedOrder;
use App\Imports\NormalizedReturn;
use App\Models\AuditLog;
use App\Models\OrderReturn;
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

function adminUserForScan(int $tenantId): User
{
    $user = User::factory()->create(['tenant_id' => $tenantId]);
    $user->assignRole('Admin');

    return $user;
}

/** hookVariant()/pools()/importShop() come from earlier test files. */
function scannableReturn(Shop $shop, ReturnType $type = ReturnType::ReturnAndRefund, int $qty = 2): OrderReturn
{
    $variant = hookVariant('RS-1', 10);
    app(CreateListing::class)->handle($shop, $variant->product()->firstOrFail());

    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-S1',
        status: OrderStatus::Completed,
        lines: [['variant' => $variant, 'qty' => 3, 'unit_price' => Money::fromBaht('159')]],
    ));

    return app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'RET-S1',
        returnType: $type,
        subStatus: ReturnSubStatus::CourierClaimsDelivered,
        lines: [['order_line' => $order->lines->firstOrFail(), 'qty' => $qty]],
    ));
}

it('credits stock per Return Line × qty on scan and locks the Return at รับของกลับแล้ว', function () {
    $shop = importShop();
    $return = scannableReturn($shop);
    $variant = $return->lines->firstOrFail()->orderLine()->firstOrFail()->variant()->firstOrFail();

    // On-Hand 10 − 3 shipped on import = 7; the scan credits the 2 back.
    app(RecordInboundScan::class)->handle($return);

    expect(pools($variant))->toBe([9, 0])
        ->and($return->refresh()->sub_status)->toBe(ReturnSubStatus::Received)
        ->and(AuditLog::query()->where('action', 'return.inbound_scan')->exists())->toBeTrue();
});

it('routes damaged units to the Damaged pool at scan', function () {
    $shop = importShop();
    $return = scannableReturn($shop);
    $line = $return->lines->firstOrFail();
    $variant = $line->orderLine()->firstOrFail()->variant()->firstOrFail();

    app(RecordInboundScan::class)->handle($return, damagedReturnLineIds: [$line->id]);

    $balance = StockBalance::query()->where('variant_id', $variant->id)->firstOrFail();

    // 10 − 3 shipped = 7; the 2 damaged units come back but go straight
    // to the Damaged pool, not On-Hand.
    expect(pools($variant))->toBe([7, 0])
        ->and($balance->damaged)->toBe(2);
});

it('refuses scanning a refund_only Return — no goods ever come back', function () {
    $shop = importShop();

    app(RecordInboundScan::class)->handle(scannableReturn($shop, ReturnType::RefundOnly));
})->throws(LogicException::class, 'refund_only');

it('refuses a second scan — รับของกลับแล้ว is terminal, stock never double-credits', function () {
    $shop = importShop();
    $return = scannableReturn($shop);

    app(RecordInboundScan::class)->handle($return);
    app(RecordInboundScan::class)->handle($return->refresh());
})->throws(LogicException::class, 'terminal');

it('requires the return.manage permission', function () {
    $shop = importShop();
    $return = scannableReturn($shop);

    $cashier = User::factory()->create(['tenant_id' => $return->tenant_id]);
    $cashier->assignRole('Cashier');
    actingAs($cashier);

    app(RecordInboundScan::class)->handle($return);
})->throws(AuthorizationException::class);

it('credits Bundle components on scan, never bundle stock', function () {
    $shop = importShop();
    $soap = hookVariant('RS-SOAP', 10);
    $bundle = hookVariant('RS-SET');
    app(DefineBundle::class)->handle($bundle, [[$soap, 2]]);
    app(CreateListing::class)->handle($shop, $bundle->product()->firstOrFail());

    $order = app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-S2',
        status: OrderStatus::Completed,
        lines: [['variant' => $bundle, 'qty' => 1, 'unit_price' => Money::fromBaht('300')]],
    ));
    $return = app(UpsertReturn::class)->handle($shop, new NormalizedReturn(
        platformReturnId: 'RET-S2',
        returnType: ReturnType::ReturnAndRefund,
        subStatus: ReturnSubStatus::CourierClaimsDelivered,
        lines: [['order_line' => $order->lines->firstOrFail(), 'qty' => 1]],
    ));

    // Soap 10 − 2 shipped via the bundle = 8; the scan credits them back.
    app(RecordInboundScan::class)->handle($return);

    expect(pools($soap))->toBe([10, 0])
        ->and(pools($bundle))->toBe([0, 0]);
});
