<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\DefineBundle;
use App\Actions\Catalog\SetCostPrice;
use App\Actions\Stock\AppendStockMovement;
use App\Actions\Stock\ExpandBundleMovements;
use App\Actions\Tenants\CreateTenant;
use App\Enums\StockAction;
use App\Models\BundleComponent;
use App\Models\Location;
use App\Models\StockBalance;
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

function makeVariant(string $sku): Variant
{
    return app(CreateProduct::class)
        ->handle("สินค้า {$sku}", [['master_sku' => $sku, 'list_price' => Money::fromBaht('100')]])
        ->variants->firstOrFail();
}

function bundleLocation(): Location
{
    return Location::query()->where('is_default', true)->firstOrFail();
}

function poolAt(Variant $variant, Location $location): ?StockBalance
{
    return StockBalance::query()
        ->where('variant_id', $variant->id)
        ->where('location_id', $location->id)
        ->first();
}

it('defines a bundle by its BOM', function () {
    $bundle = makeVariant('SET-1');
    $soap = makeVariant('SOAP');
    $towel = makeVariant('TOWEL');

    app(DefineBundle::class)->handle($bundle, [
        [$soap, 2],
        [$towel, 1],
    ]);

    expect($bundle->isBundle())->toBeTrue()
        ->and($soap->isBundle())->toBeFalse()
        ->and(BundleComponent::query()->count())->toBe(2);
});

it('refuses a bundle containing itself', function () {
    $bundle = makeVariant('SET-1');

    app(DefineBundle::class)->handle($bundle, [[$bundle, 1]]);
})->throws(InvalidArgumentException::class, 'its own component');

it('refuses a bundle as a component of another bundle', function () {
    $inner = makeVariant('SET-IN');
    app(DefineBundle::class)->handle($inner, [[makeVariant('SOAP'), 1]]);

    app(DefineBundle::class)->handle(makeVariant('SET-OUT'), [[$inner, 1]]);
})->throws(InvalidArgumentException::class, 'bundle cannot contain another bundle');

it('refuses turning a variant that already holds stock into a bundle', function () {
    $variant = makeVariant('HASSTOCK');
    app(AppendStockMovement::class)->handle($variant, bundleLocation(), StockAction::Receive, 5);

    app(DefineBundle::class)->handle($variant, [[makeVariant('SOAP'), 1]]);
})->throws(InvalidArgumentException::class, 'already holds stock');

it('never moves bundle stock through the ledger directly', function () {
    $bundle = makeVariant('SET-1');
    app(DefineBundle::class)->handle($bundle, [[makeVariant('SOAP'), 1]]);

    app(AppendStockMovement::class)->handle($bundle, bundleLocation(), StockAction::Receive, 5);
})->throws(InvalidArgumentException::class, 'virtual');

it('derives bundle Available as min(floor(component available / qty))', function () {
    $bundle = makeVariant('SET-1');
    $soap = makeVariant('SOAP');
    $towel = makeVariant('TOWEL');
    $location = bundleLocation();
    app(DefineBundle::class)->handle($bundle, [[$soap, 2], [$towel, 1]]);

    $append = app(AppendStockMovement::class);
    $append->handle($soap, $location, StockAction::Receive, 7);  // floor(7/2) = 3
    $append->handle($towel, $location, StockAction::Receive, 5); // floor(5/1) = 5

    expect($bundle->availableAt($location))->toBe(3)
        ->and($soap->availableAt($location))->toBe(7);
});

it('expands a bundle RESERVE into component reservations atomically', function () {
    $bundle = makeVariant('SET-1');
    $soap = makeVariant('SOAP');
    $towel = makeVariant('TOWEL');
    $location = bundleLocation();
    app(DefineBundle::class)->handle($bundle, [[$soap, 2], [$towel, 1]]);

    app(ExpandBundleMovements::class)->handle($bundle, $location, StockAction::Reserve, 3);

    expect(poolAt($soap, $location)?->reserved)->toBe(6)
        ->and(poolAt($towel, $location)?->reserved)->toBe(3)
        ->and(poolAt($bundle, $location))->toBeNull();
});

it('expands a marketplace bundle SHIP releasing the component reservations', function () {
    $bundle = makeVariant('SET-1');
    $soap = makeVariant('SOAP');
    $location = bundleLocation();
    app(DefineBundle::class)->handle($bundle, [[$soap, 2]]);

    $append = app(AppendStockMovement::class);
    $append->handle($soap, $location, StockAction::Receive, 10);
    $expand = app(ExpandBundleMovements::class);
    $expand->handle($bundle, $location, StockAction::Reserve, 3);
    $expand->handle($bundle, $location, StockAction::Ship, 3, reservedReleased: 3);

    expect(poolAt($soap, $location)?->on_hand)->toBe(4)
        ->and(poolAt($soap, $location)?->reserved)->toBe(0);
});

it('expands a POS bundle SHIP without touching component reservations', function () {
    $bundle = makeVariant('SET-1');
    $soap = makeVariant('SOAP');
    $location = bundleLocation();
    app(DefineBundle::class)->handle($bundle, [[$soap, 2]]);

    app(AppendStockMovement::class)->handle($soap, $location, StockAction::Receive, 10);
    app(ExpandBundleMovements::class)->handle($bundle, $location, StockAction::Ship, 1, reservedReleased: 0);

    expect(poolAt($soap, $location)?->on_hand)->toBe(8)
        ->and(poolAt($soap, $location)?->reserved)->toBe(0);
});

it('refuses expanding an action outside RESERVE/SHIP/RELEASE', function () {
    $bundle = makeVariant('SET-1');
    app(DefineBundle::class)->handle($bundle, [[makeVariant('SOAP'), 1]]);

    app(ExpandBundleMovements::class)->handle($bundle, bundleLocation(), StockAction::Receive, 1);
})->throws(InvalidArgumentException::class, 'RESERVE, SHIP, or RELEASE');

it('computes bundle cost as the sum of component costs at the date', function () {
    $bundle = makeVariant('SET-1');
    $soap = makeVariant('SOAP');
    $towel = makeVariant('TOWEL');
    app(DefineBundle::class)->handle($bundle, [[$soap, 2], [$towel, 1]]);

    $set = app(SetCostPrice::class);
    $set->handle($soap, Money::fromBaht('10'), Carbon::parse('2026-01-01'));
    $set->handle($towel, Money::fromBaht('25.50'), Carbon::parse('2026-01-01'));

    expect($bundle->costAt(Carbon::parse('2026-02-01'))?->satang)->toBe(4550); // 2×10 + 25.50
});

it('passes the cross-tenant isolation harness (bundle components)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): BundleComponent {
        $sequence++;
        $bundle = makeVariant("SET-H-{$sequence}");
        app(DefineBundle::class)->handle($bundle, [[makeVariant("COMP-H-{$sequence}"), 1]]);

        return BundleComponent::query()->firstOrFail();
    });
});
