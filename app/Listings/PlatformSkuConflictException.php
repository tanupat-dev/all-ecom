<?php

namespace App\Listings;

use RuntimeException;

/**
 * The one illegal mapping case (CONTEXT.md: Platform SKU): the same
 * (Shop, Platform SKU) pointing at two different Variants. Fail-loud
 * (ADR 0005) — surfaced for the seller to resolve, never guessed.
 */
class PlatformSkuConflictException extends RuntimeException
{
    public static function for(string $platformSku, string $existingMasterSku): self
    {
        return new self(
            "Platform SKU [{$platformSku}] already resolves to Variant [{$existingMasterSku}] on this Shop — one SKU cannot point at two Variants."
        );
    }
}
