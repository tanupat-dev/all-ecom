<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Integer satang column ↔ Money value object (ADR 0015).
 *
 * @implements CastsAttributes<Money, Money>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException("Money column [{$key}] must be an integer satang value.");
        }

        return Money::fromSatang($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("Attribute [{$key}] must be set with a ".Money::class.' instance — never a float or raw number (ADR 0015).');
        }

        return $value->satang;
    }
}
