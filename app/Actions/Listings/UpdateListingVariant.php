<?php

namespace App\Actions\Listings;

use App\Listings\PlatformSkuConflictException;
use App\Models\ListingVariant;
use Illuminate\Support\Facades\DB;

/**
 * Overrides one mapping row's Platform SKU (CONTEXT.md: Platform SKU). Guards
 * the resolution function: the new SKU must not already resolve to a different
 * Variant on the same Shop.
 *
 * It does NOT touch Deal Price: ListingVariant.deal_price is a denormalized
 * cache of the active Promotion Line (ADR 0021), written only by the Promotion
 * machinery (RefreshDealPriceCache) — never edited independently here.
 */
class UpdateListingVariant
{
    public function handle(ListingVariant $mapping, string $platformSku): ListingVariant
    {
        return DB::transaction(function () use ($mapping, $platformSku): ListingVariant {
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
            ]);

            return $mapping->refresh();
        });
    }
}
