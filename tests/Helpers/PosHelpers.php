<?php

use App\Actions\Catalog\CreateProduct;
use App\Actions\Pos\OpenShift;
use App\Actions\Shops\CreateShop;
use App\Actions\Stock\AppendStockMovement;
use App\Enums\Platform;
use App\Enums\StockAction;
use App\Models\Location;
use App\Models\Register;
use App\Models\Shift;
use App\Models\Variant;
use App\Support\Money;

/**
 * Shared POS test fixtures: a pos Shop + its open Shift, and a priced,
 * stocked Variant. Assume an authenticated user with pos.open_shift.
 */
function openPosShift(): Shift
{
    app(CreateShop::class)->handle('หน้าร้าน', Platform::Pos, Location::query()->firstOrFail());

    return app(OpenShift::class)->handle(Register::query()->firstOrFail(), Money::fromBaht('1000'));
}

function posVariant(string $sku, string $priceBaht, int $onHand = 10): Variant
{
    $variant = app(CreateProduct::class)
        ->handle("สินค้า {$sku}", [['master_sku' => $sku, 'list_price' => Money::fromBaht($priceBaht)]])
        ->variants->firstOrFail();

    app(AppendStockMovement::class)->handle(
        $variant, Location::query()->firstOrFail(), StockAction::Receive, $onHand,
    );

    return $variant;
}
