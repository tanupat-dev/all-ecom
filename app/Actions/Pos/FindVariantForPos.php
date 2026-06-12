<?php

namespace App\Actions\Pos;

use App\Models\Variant;
use InvalidArgumentException;

/**
 * Resolves a scanned/typed code to a Variant — barcode first, then Master
 * SKU (CONTEXT.md: Checkout). Unknown code fails loud, never guesses.
 */
class FindVariantForPos
{
    public function handle(string $code): Variant
    {
        $code = trim($code);

        if ($code === '') {
            throw new InvalidArgumentException('Scan or type a barcode / Master SKU.');
        }

        return Variant::query()->where('barcode', $code)->first()
            ?? Variant::query()->where('master_sku', $code)->first()
            ?? throw new InvalidArgumentException("ไม่พบสินค้า [{$code}] — ตรวจสอบบาร์โค้ดหรือ Master SKU");
    }
}
