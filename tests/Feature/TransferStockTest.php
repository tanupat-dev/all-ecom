<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Stock\AppendStockMovement;
use App\Actions\Stock\TransferStock;
use App\Actions\Tenants\CreateTenant;
use App\Enums\StockAction;
use App\Models\Location;
use App\Models\StockBalance;
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

function transferVariant(): Variant
{
    return app(CreateProduct::class)
        ->handle('สินค้า', [['master_sku' => 'TRF-1', 'list_price' => Money::fromBaht('100')]])
        ->variants->firstOrFail();
}

function onHandAt(Variant $variant, Location $location): int
{
    $balance = StockBalance::query()
        ->where('variant_id', $variant->id)
        ->where('location_id', $location->id)
        ->first();

    return $balance->on_hand ?? 0;
}

it('moves stock between Locations as a linked OUT+IN pair — nothing created or destroyed', function () {
    $variant = transferVariant();
    $source = Location::query()->where('is_default', true)->firstOrFail();
    $destination = Location::query()->create(['name' => 'คลังรอง']);

    app(AppendStockMovement::class)->handle($variant, $source, StockAction::Receive, 10);

    [$out, $in] = app(TransferStock::class)->handle($variant, $source, $destination, 4);

    expect(onHandAt($variant, $source))->toBe(6)
        ->and(onHandAt($variant, $destination))->toBe(4)
        ->and($out->action)->toBe(StockAction::TransferOut)
        ->and($in->action)->toBe(StockAction::TransferIn)
        ->and($in->ref_type)->toBe($out->getMorphClass())
        ->and((int) $in->ref_id)->toBe($out->id);
});

it('allows the source to go negative — oversell is information, not a block', function () {
    $variant = transferVariant();
    $source = Location::query()->where('is_default', true)->firstOrFail();
    $destination = Location::query()->create(['name' => 'คลังรอง']);

    app(TransferStock::class)->handle($variant, $source, $destination, 3);

    expect(onHandAt($variant, $source))->toBe(-3)
        ->and(onHandAt($variant, $destination))->toBe(3);
});

it('refuses a transfer to the same Location', function () {
    $variant = transferVariant();
    $source = Location::query()->where('is_default', true)->firstOrFail();

    app(TransferStock::class)->handle($variant, $source, $source, 1);
})->throws(InvalidArgumentException::class, 'different Locations');
