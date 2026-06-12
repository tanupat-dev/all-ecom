<?php

namespace App\Actions\Listings;

use App\Enums\PlatformType;
use App\Listings\PlatformSkuConflictException;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Places a Product on a marketplace Shop, mapping every Variant with its
 * Platform SKU defaulting to the Master SKU (CONTEXT.md: Listing,
 * Platform SKU). Only marketplace Shops carry Listings (ADR 0010).
 */
class CreateListing
{
    public function handle(Shop $shop, Product $product): Listing
    {
        if ($shop->platform_type !== PlatformType::Marketplace) {
            throw new InvalidArgumentException(
                "Only a marketplace Shop carries Listings (ADR 0010); [{$shop->name}] is {$shop->platform_type->value}."
            );
        }

        return DB::transaction(function () use ($shop, $product): Listing {
            $listing = Listing::query()->create([
                'shop_id' => $shop->id,
                'product_id' => $product->id,
            ]);

            foreach ($product->variants as $variant) {
                $conflicting = ListingVariant::query()
                    ->conflictingWith($shop->id, $variant->master_sku, $variant->id)
                    ->lockForUpdate()
                    ->first();

                if ($conflicting !== null) {
                    throw PlatformSkuConflictException::for(
                        $variant->master_sku,
                        $conflicting->variant()->firstOrFail()->master_sku,
                    );
                }

                $listing->variants()->create([
                    'shop_id' => $shop->id,
                    'variant_id' => $variant->id,
                    'platform_sku' => $variant->master_sku,
                ]);
            }

            return $listing->load('variants');
        });
    }
}
