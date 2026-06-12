<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Two-value state on each ListingVariant tracking how sure we are the listing
 * is live (CONTEXT.md: Listing Status; ADR 0019).
 *
 * `draft`  — the seller filled a Channel Upload Template but hasn't confirmed
 *             the upload to the Platform yet.
 * `listed` — the seller confirmed the upload, OR the row came from importing
 *             the Platform's existing-product export (ground truth).
 *
 * The DB default is `listed` because every CURRENT writer (order/return
 * importer or manual platform-SKU mapping) creates a ListingVariant from
 * Platform reality = ground truth. Only the future Channel-Upload-Template
 * fill engine (#57–#59) will explicitly write `draft`.
 */
enum ListingStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Listed = 'listed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'ร่าง',
            self::Listed => 'ลงแล้ว',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Listed => 'success',
        };
    }
}
