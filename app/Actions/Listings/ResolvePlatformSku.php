<?php

namespace App\Actions\Listings;

use App\Listings\UnresolvedPlatformSkuException;
use App\Models\ListingVariant;
use App\Models\Shop;
use App\Models\Variant;

/**
 * The per-Shop resolution map (CONTEXT.md: Platform SKU): a function from
 * (Shop, Platform SKU) to exactly one Variant. Many-to-one is fine; no
 * mapping is fail-loud (ADR 0005).
 */
class ResolvePlatformSku
{
    public function handle(Shop $shop, string $platformSku): Variant
    {
        $mapping = ListingVariant::query()
            ->where('shop_id', $shop->id)
            ->where('platform_sku', $platformSku)
            ->first();

        if ($mapping === null) {
            throw UnresolvedPlatformSkuException::for($shop, $platformSku);
        }

        return $mapping->variant()->firstOrFail();
    }
}
