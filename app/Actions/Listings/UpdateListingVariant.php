<?php

namespace App\Actions\Listings;

use App\Listings\PlatformSkuConflictException;
use App\Models\ListingVariant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Overrides one mapping row's Platform SKU and/or Deal Price (CONTEXT.md:
 * Platform SKU, Deal Price). Guards the resolution function: the new SKU
 * must not already resolve to a different Variant on the same Shop.
 */
class UpdateListingVariant
{
    public function handle(ListingVariant $mapping, string $platformSku, ?Money $dealPrice): ListingVariant
    {
        return DB::transaction(function () use ($mapping, $platformSku, $dealPrice): ListingVariant {
            $conflicting = ListingVariant::query()
                ->conflictingWith($mapping->shop_id, $platformSku, $mapping->variant_id)
                ->lockForUpdate()
                ->first();

            if ($conflicting !== null) {
                throw PlatformSkuConflictException::for(
                    $platformSku,
                    $conflicting->variant()->firstOrFail()->master_sku,
                );
            }

            $mapping->update([
                'platform_sku' => $platformSku,
                'deal_price' => $dealPrice,
            ]);

            return $mapping->refresh();
        });
    }
}
