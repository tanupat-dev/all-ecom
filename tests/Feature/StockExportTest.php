<?php

use App\Actions\Catalog\DefineBundle;
use App\Actions\Listings\CreateListing;
use App\Actions\Listings\UpdateListingVariant;
use App\Actions\Shops\CreateShop;
use App\Actions\Stock\AppendStockMovement;
use App\Actions\Stock\ExportShopStock;
use App\Actions\Tenants\CreateTenant;
use App\Enums\Platform;
use App\Enums\StockAction;
use App\Models\Location;
use App\Models\Shop;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Variant;
use App\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function exportShop(): Shop
{
    $location = Location::query()->where('is_default', true)->firstOrFail();

    return app(CreateShop::class)->handle('shopee1', Platform::Shopee, $location);
}

/** hookVariant() comes from OrderStockHooksTest. */
function exportListedVariant(Shop $shop, string $sku, int $onHand): Variant
{
    $variant = hookVariant($sku, $onHand);
    app(CreateListing::class)->handle($shop, $variant->product()->firstOrFail());

    return $variant;
}

it('exports each Platform SKU with its fulfilment Location Available, clamping negative to 0', function () {
    $shop = exportShop();
    $a = exportListedVariant($shop, 'EX-1', 7);
    $b = exportListedVariant($shop, 'EX-2', 2);

    // Buffer is held back inside the Available derivation (CONTEXT.md: Buffer).
    StockBalance::query()->where('variant_id', $a->id)->update(['buffer' => 3]);
    // Oversold: reserved beyond on-hand → negative Available → exports 0.
    app(AppendStockMovement::class)->handle($b, Location::query()->firstOrFail(), StockAction::Reserve, 5);

    $rows = app(ExportShopStock::class)->handle($shop);

    expect($rows)->toBe([
        ['platform_sku' => 'EX-1', 'qty' => 4],
        ['platform_sku' => 'EX-2', 'qty' => 0],
    ]);
});

it('writes the same Available to every Platform SKU of one Variant — one shared pool', function () {
    $shop = exportShop();
    $a = exportListedVariant($shop, 'EX-1', 5);
    // The seller relisted the same Product; one mapping carries a legacy code.
    $second = app(CreateListing::class)->handle($shop, $a->product()->firstOrFail());
    app(UpdateListingVariant::class)->handle($second->variants()->firstOrFail(), 'OLD-CODE-1');

    $rows = app(ExportShopStock::class)->handle($shop);

    expect($rows)->toBe([
        ['platform_sku' => 'EX-1', 'qty' => 5],
        ['platform_sku' => 'OLD-CODE-1', 'qty' => 5],
    ]);
});

it('exports a Bundle at its derived Available', function () {
    $shop = exportShop();
    $soap = hookVariant('EX-SOAP', 7);
    $bundle = exportListedVariant($shop, 'EX-SET', 0);
    app(DefineBundle::class)->handle($bundle, [[$soap, 2]]);

    $rows = app(ExportShopStock::class)->handle($shop);

    expect($rows)->toBe([['platform_sku' => 'EX-SET', 'qty' => 3]]);
});

it('counts only the Shop fulfilment Location, not other Locations', function () {
    $shop = exportShop();
    $a = exportListedVariant($shop, 'EX-1', 4);
    $other = Location::query()->create(['name' => 'คลังสอง']);
    app(AppendStockMovement::class)->handle($a, $other, StockAction::Receive, 100);

    $rows = app(ExportShopStock::class)->handle($shop);

    expect($rows)->toBe([['platform_sku' => 'EX-1', 'qty' => 4]]);
});

it('refuses exporting a non-marketplace Shop', function () {
    $location = Location::query()->where('is_default', true)->firstOrFail();
    $pos = app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, $location);

    app(ExportShopStock::class)->handle($pos);
})->throws(LogicException::class, 'marketplace');

it('gates the export on stock.view via the Shop policy', function () {
    [$tenant, $admin] = tenantWithUser('Admin');
    $shop = exportShop();

    expect($admin->can('exportStock', $shop))->toBeTrue();

    $blind = Role::findOrCreate('ไม่เห็นสต็อก', 'web');
    $blind->syncPermissions(['product.view']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($blind);

    expect($user->can('exportStock', $shop))->toBeFalse();
});
