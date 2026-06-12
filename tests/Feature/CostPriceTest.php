<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\SetCostPrice;
use App\Actions\Tenants\CreateTenant;
use App\Models\CostPrice;
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

function costVariant(string $sku = 'COST-1'): Variant
{
    return app(CreateProduct::class)
        ->handle('สินค้า', [['master_sku' => $sku, 'list_price' => Money::fromBaht('100')]])
        ->variants->firstOrFail();
}

it('answers the cost at a given date from history', function () {
    $variant = costVariant();
    $set = app(SetCostPrice::class);

    $set->handle($variant, Money::fromBaht('40'), Carbon::parse('2026-01-01'));
    $set->handle($variant, Money::fromBaht('55'), Carbon::parse('2026-06-01'));

    expect($variant->costAt(Carbon::parse('2026-03-15'))?->satang)->toBe(4000)
        ->and($variant->costAt(Carbon::parse('2026-06-01'))?->satang)->toBe(5500)
        ->and($variant->costAt(Carbon::parse('2025-12-31')))->toBeNull()
        ->and($variant->currentCost()?->satang)->toBe(5500);
});

it('appends history — setting a new cost never overwrites the old row', function () {
    $variant = costVariant();
    $set = app(SetCostPrice::class);

    $set->handle($variant, Money::fromBaht('40'), Carbon::parse('2026-01-01'));
    $set->handle($variant, Money::fromBaht('55'), Carbon::parse('2026-06-01'));

    expect(CostPrice::query()->count())->toBe(2);
});

it('never updates or deletes a cost history row', function () {
    $variant = costVariant();
    $row = app(SetCostPrice::class)->handle($variant, Money::fromBaht('40'), Carbon::parse('2026-01-01'));

    expect(fn () => $row->update(['cost' => Money::fromBaht('1')]))
        ->toThrow(LogicException::class, 'history')
        ->and(fn () => $row->delete())
        ->toThrow(LogicException::class, 'history');
});

it('passes the cross-tenant isolation harness', function () {
    $sequence = 0;

    assertTenantIsolation(function () use (&$sequence): CostPrice {
        $sequence++;

        return app(SetCostPrice::class)->handle(costVariant("COST-H-{$sequence}"), Money::fromBaht('40'), Carbon::parse('2026-01-01'));
    });
});
