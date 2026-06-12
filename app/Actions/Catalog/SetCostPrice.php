<?php

namespace App\Actions\Catalog;

use App\Models\CostPrice;
use App\Models\Variant;
use App\Support\Money;
use DateTimeInterface;

/**
 * Appends one row to a Variant's cost history (CONVENTIONS rule 9).
 */
class SetCostPrice
{
    public function handle(Variant $variant, Money $cost, ?DateTimeInterface $validFrom = null): CostPrice
    {
        return CostPrice::query()->create([
            'variant_id' => $variant->id,
            'cost' => $cost,
            'valid_from' => $validFrom ?? now(),
        ]);
    }
}
