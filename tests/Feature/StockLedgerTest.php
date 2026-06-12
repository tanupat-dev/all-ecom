<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Stock\AppendStockMovement;
use App\Actions\Tenants\CreateTenant;
use App\Enums\StockAction;
use App\Filament\Resources\StockBalances\StockBalanceResource;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Support\Money;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(TenantContext::class)->forget();
    $tenant = app(CreateTenant::class)->handle('A');
    app(TenantContext::class)->set($tenant);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function aVariant(string $sku = 'SKU-1'): Variant
{
    return app(CreateProduct::class)
        ->handle('สินค้า', [['master_sku' => $sku, 'list_price' => Money::fromBaht('100')]])
        ->variants->firstOrFail();
}

function defaultLocation(): Location
{
    return Location::query()->where('is_default', true)->firstOrFail();
}

function balance(Variant $variant, Location $location): ?StockBalance
{
    return StockBalance::query()
        ->where('variant_id', $variant->id)
        ->where('location_id', $location->id)
        ->first();
}

it('RECEIVE appends a movement and raises On-Hand in the same call', function () {
    $variant = aVariant();
    $location = defaultLocation();

    $movement = app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, 10);

    expect($movement->qty_delta)->toBe(10)
        ->and(balance($variant, $location)?->on_hand)->toBe(10)
        ->and(balance($variant, $location)?->reserved)->toBe(0);
});

it('RESERVE and RELEASE move the Reserved pool only', function () {
    $variant = aVariant();
    $location = defaultLocation();
    $append = app(AppendStockMovement::class);

    $append->handle($variant, $location, StockAction::Receive, 10);
    $append->handle($variant, $location, StockAction::Reserve, 4);

    expect(balance($variant, $location)?->reserved)->toBe(4)
        ->and(balance($variant, $location)?->on_hand)->toBe(10);

    $append->handle($variant, $location, StockAction::Release, 1);

    expect(balance($variant, $location)?->reserved)->toBe(3);
});

it('SHIP for a marketplace order cuts On-Hand and the reservation it held', function () {
    $variant = aVariant();
    $location = defaultLocation();
    $append = app(AppendStockMovement::class);

    $append->handle($variant, $location, StockAction::Receive, 10);
    $append->handle($variant, $location, StockAction::Reserve, 4);
    $append->handle($variant, $location, StockAction::Ship, 4, reservedReleased: 4);

    expect(balance($variant, $location)?->on_hand)->toBe(6)
        ->and(balance($variant, $location)?->reserved)->toBe(0);
});

it('SHIP for a POS sale cuts On-Hand only — POS never touches Reserved', function () {
    $variant = aVariant();
    $location = defaultLocation();
    $append = app(AppendStockMovement::class);

    $append->handle($variant, $location, StockAction::Receive, 10);
    $append->handle($variant, $location, StockAction::Reserve, 4);
    $append->handle($variant, $location, StockAction::Ship, 2, reservedReleased: 0);

    expect(balance($variant, $location)?->on_hand)->toBe(8)
        ->and(balance($variant, $location)?->reserved)->toBe(4);
});

it('DAMAGE and RESTORE move stock between On-Hand and Damaged', function () {
    $variant = aVariant();
    $location = defaultLocation();
    $append = app(AppendStockMovement::class);

    $append->handle($variant, $location, StockAction::Receive, 10);
    $append->handle($variant, $location, StockAction::Damage, 3);

    expect(balance($variant, $location)?->on_hand)->toBe(7)
        ->and(balance($variant, $location)?->damaged)->toBe(3);

    $append->handle($variant, $location, StockAction::Restore, 2);

    expect(balance($variant, $location)?->on_hand)->toBe(9)
        ->and(balance($variant, $location)?->damaged)->toBe(1);
});

it('RECOUNT applies a signed delta to On-Hand directly', function () {
    $variant = aVariant();
    $location = defaultLocation();
    $append = app(AppendStockMovement::class);

    $append->handle($variant, $location, StockAction::Receive, 10);
    $append->handle($variant, $location, StockAction::Recount, -3);

    expect(balance($variant, $location)?->on_hand)->toBe(7);

    $append->handle($variant, $location, StockAction::Recount, 5);

    expect(balance($variant, $location)?->on_hand)->toBe(12);
});

it('commits the movement and the balance together — or neither', function () {
    $variant = aVariant();
    $location = defaultLocation();

    try {
        DB::transaction(function () use ($variant, $location) {
            app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, 10);

            throw new RuntimeException('caller fails after appending');
        });
    } catch (RuntimeException) {
    }

    expect(StockMovement::query()->count())->toBe(0)
        ->and(balance($variant, $location))->toBeNull();
});

it('never updates or deletes a ledger entry', function () {
    $variant = aVariant();
    $movement = app(AppendStockMovement::class)->handle($variant, defaultLocation(), StockAction::Receive, 10);

    expect(fn () => $movement->update(['qty_delta' => 99]))
        ->toThrow(LogicException::class, 'immutable ledger')
        ->and(fn () => $movement->delete())
        ->toThrow(LogicException::class, 'immutable ledger');
});

it('refuses a SHIP that does not state what the order reserved', function () {
    app(AppendStockMovement::class)->handle(aVariant(), defaultLocation(), StockAction::Ship, 2);
})->throws(InvalidArgumentException::class, 'order-aware');

it('refuses releasing more reservation than the shipped qty', function () {
    app(AppendStockMovement::class)->handle(aVariant(), defaultLocation(), StockAction::Ship, 2, reservedReleased: 3);
})->throws(InvalidArgumentException::class, 'between 0 and the shipped qty');

it('refuses reservedReleased on a non-SHIP action', function () {
    app(AppendStockMovement::class)->handle(aVariant(), defaultLocation(), StockAction::Receive, 2, reservedReleased: 0);
})->throws(InvalidArgumentException::class, 'only applies to SHIP');

it('refuses a non-positive qty outside RECOUNT', function () {
    app(AppendStockMovement::class)->handle(aVariant(), defaultLocation(), StockAction::Receive, -5);
})->throws(InvalidArgumentException::class, 'positive magnitude');

it('refuses a RECOUNT with no delta', function () {
    app(AppendStockMovement::class)->handle(aVariant(), defaultLocation(), StockAction::Recount, 0);
})->throws(InvalidArgumentException::class, 'records nothing');

it('passes the cross-tenant isolation harness (movements)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): StockMovement {
        $sequence++;
        $variant = aVariant("LEDGER-{$sequence}");
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);

        return app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, 1);
    });
});

it('passes the cross-tenant isolation harness (balances)', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): StockBalance {
        $sequence++;
        $variant = aVariant("BAL-{$sequence}");
        $location = Location::query()->first() ?? Location::query()->create(['name' => 'คลัง']);
        app(AppendStockMovement::class)->handle($variant, $location, StockAction::Receive, 1);

        return balance($variant, $location) ?? throw new RuntimeException('balance row missing');
    });
});

it('shows the balances page with a negative Available visible', function () {
    $variant = aVariant();
    $location = defaultLocation();
    $append = app(AppendStockMovement::class);
    $append->handle($variant, $location, StockAction::Receive, 2);
    $append->handle($variant, $location, StockAction::Reserve, 5);

    Pest\Laravel\actingAs(User::factory()->create());

    Pest\Laravel\get(StockBalanceResource::getUrl('index'))
        ->assertOk()
        ->assertSee('SKU-1')
        ->assertSee('-3');
});

it('Available = On-Hand − Reserved − Buffer and may go negative (oversell)', function () {
    $variant = aVariant();
    $location = defaultLocation();
    $append = app(AppendStockMovement::class);

    $append->handle($variant, $location, StockAction::Receive, 5);
    $append->handle($variant, $location, StockAction::Reserve, 4);

    $stockBalance = balance($variant, $location);
    $stockBalance?->update(['buffer' => 3]);

    expect($stockBalance?->refresh()->available)->toBe(-2);
});
