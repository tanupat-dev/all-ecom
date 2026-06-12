<?php

use App\Actions\Catalog\DefineBundle;
use App\Actions\Listings\CreateListing;
use App\Actions\Orders\ImportMarketplaceOrder;
use App\Actions\Shops\CreateShop;
use App\Actions\Stock\ListOversellConflicts;
use App\Actions\Tenants\CreateTenant;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Filament\Pages\OversellAlerts;
use App\Imports\NormalizedOrder;
use App\Models\Location;
use App\Models\Shop;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;

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

function oversellShop(): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle('shopee1', Platform::Shopee, $location);
}

/** hookVariant() comes from OrderStockHooksTest. */
function oversoldVariant(Shop $shop, string $sku, int $onHand): Variant
{
    $variant = hookVariant($sku, $onHand);
    app(CreateListing::class)->handle($shop, $variant->product()->firstOrFail());

    return $variant;
}

function reserveViaImport(Shop $shop, string $orderId, Variant $variant, int $qty, string $createdAt): void
{
    app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: $orderId,
        status: OrderStatus::AwaitingPack,
        lines: [['variant' => $variant, 'qty' => $qty, 'unit_price' => Money::fromBaht('159')]],
        milestones: ['created_date' => new DateTimeImmutable($createdAt)],
    ));
}

it('lists a negative-Available Variant with its conflicting pre-pack Orders, suggesting the latest as cancel candidates', function () {
    $shop = oversellShop();
    $a = oversoldVariant($shop, 'OV-1', 3);

    // First-come-first-served: the earlier order is honoured; the later
    // one that pushed Available negative is the cancel candidate.
    reserveViaImport($shop, 'SP-100', $a, 3, '2026-06-10 09:00:00');
    reserveViaImport($shop, 'SP-101', $a, 2, '2026-06-11 09:00:00');

    $alerts = app(ListOversellConflicts::class)->handle();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['balance']->available)->toBe(-2)
        ->and($alerts[0]['conflicts'])->toHaveCount(2)
        ->and($alerts[0]['conflicts'][0]['order']->platform_order_id)->toBe('SP-101')
        ->and($alerts[0]['conflicts'][0]['suggested'])->toBeTrue()
        ->and($alerts[0]['conflicts'][1]['order']->platform_order_id)->toBe('SP-100')
        ->and($alerts[0]['conflicts'][1]['suggested'])->toBeFalse();
});

it('clears the alert once the cancel re-imports as ยกเลิก — the import-driven resolution', function () {
    $shop = oversellShop();
    $a = oversoldVariant($shop, 'OV-1', 1);

    reserveViaImport($shop, 'SP-102', $a, 2, '2026-06-11 09:00:00');
    expect(app(ListOversellConflicts::class)->handle())->toHaveCount(1);

    app(ImportMarketplaceOrder::class)->handle($shop, new NormalizedOrder(
        platformOrderId: 'SP-102',
        status: OrderStatus::Cancelled,
        lines: [['variant' => $a, 'qty' => 2, 'unit_price' => Money::fromBaht('159')]],
    ));

    expect(app(ListOversellConflicts::class)->handle())->toBe([]);
});

it('surfaces a Bundle order through its oversold component', function () {
    $shop = oversellShop();
    $soap = hookVariant('OV-SOAP', 3);
    $bundle = oversoldVariant($shop, 'OV-SET', 0);
    app(DefineBundle::class)->handle($bundle, [[$soap, 2]]);

    reserveViaImport($shop, 'SP-103', $bundle, 2, '2026-06-11 09:00:00');

    $alerts = app(ListOversellConflicts::class)->handle();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['balance']->variant_id)->toBe($soap->id)
        ->and($alerts[0]['conflicts'][0]['order']->platform_order_id)->toBe('SP-103')
        ->and($alerts[0]['conflicts'][0]['held'])->toBe(4);
});

it('gates the Oversell page on stock.view', function () {
    [$tenant, $admin] = tenantWithUser('Admin');
    actingAs($admin);
    get(OversellAlerts::getUrl())->assertOk();

    $blind = Role::findOrCreate('ไม่เห็นสต็อก', 'web');
    $blind->syncPermissions(['product.view']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($blind);
    actingAs($user);
    get(OversellAlerts::getUrl())->assertForbidden();
});
