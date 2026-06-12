<?php

namespace App\Listings;

use App\Models\Shop;
use RuntimeException;

/**
 * A (Shop, Platform SKU) lookup with no mapping — fail-loud (ADR 0005):
 * the importer holds the row; the line is never dropped or orphaned.
 */
class UnresolvedPlatformSkuException extends RuntimeException
{
    public static function for(Shop $shop, string $platformSku): self
    {
        return new self(
            "ระบบไม่รองรับ — Platform SKU [{$platformSku}] has no Variant mapping on Shop [{$shop->name}]."
        );
    }
}
