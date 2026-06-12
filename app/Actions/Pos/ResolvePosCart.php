<?php

namespace App\Actions\Pos;

use App\Models\Variant;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Prices a POS cart: List Price − Manual Discount per line.
 *
 * Rounding site (ADR 0015): a % line discount rounds HALF-UP to whole
 * satang, computed in pure basis-point integer math — no float ever
 * touches the money.
 */
class ResolvePosCart
{
    /**
     * @param  list<array{variant: Variant, qty: int, discount_baht?: Money, discount_percent?: float}>  $items
     * @return array{list<array{variant: Variant, qty: int, unit_price: Money, discount: Money}>, bool}
     */
    public function handle(array $items): array
    {
        $lines = [];
        $hasDiscount = false;

        foreach ($items as $item) {
            $unitPrice = $item['variant']->list_price
                ?? throw new InvalidArgumentException("Variant [{$item['variant']->master_sku}] has no List Price.");

            $gross = $unitPrice->multiply($item['qty']);
            $discount = $item['discount_baht'] ?? Money::fromSatang(0);

            if (isset($item['discount_percent'])) {
                $discount = $discount->add($this->percentOf($gross, $item['discount_percent']));
            }

            if ($discount->isNegative() || $gross->subtract($discount)->isNegative()) {
                throw new InvalidArgumentException('A Manual Discount must stay between zero and the line amount.');
            }

            $hasDiscount = $hasDiscount || ! $discount->isZero();

            $lines[] = [
                'variant' => $item['variant'],
                'qty' => $item['qty'],
                'unit_price' => $unitPrice,
                'discount' => $discount,
            ];
        }

        return [$lines, $hasDiscount];
    }

    private function percentOf(Money $amount, float $percent): Money
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('A % discount must be between 0 and 100.');
        }

        $basisPoints = (int) round($percent * 100);

        return Money::fromSatang(intdiv($amount->satang * $basisPoints + 5_000, 10_000));
    }
}
