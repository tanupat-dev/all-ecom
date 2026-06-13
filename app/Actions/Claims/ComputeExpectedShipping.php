<?php

namespace App\Actions\Claims;

use App\Models\Order;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Computes an Order's expected shipping fee from the catalogue (ADR 0022):
 * chargeable weight per unit = max(actual package weight, volumetric weight),
 * volumetric = L×W×H mm³ / 5000 (= grams), summed per-unit-additive across all
 * Order Lines, then mapped through the Shop's tiered `expected_shipping_rate`.
 *
 * Fail-safe (ADR 0005): when any input needed to assess the Order is missing —
 * no `expected_shipping_rate`, no Order Lines, or a contributing Variant lacking
 * weight or any dimension — returns null (the caller must NOT flag). A guessed
 * rate would mis-flag real money. A *malformed* tier (not the same as a missing
 * rate) instead fails loud, never silently mis-computes.
 *
 * Money is integer satang throughout (ADR 0015) — no float anywhere.
 */
class ComputeExpectedShipping
{
    /** mm³ → g divisor; the SEA cross-platform standard (ADR 0022 §1). */
    private const VOLUMETRIC_DIVISOR = 5000;

    public function handle(Order $order): ?Money
    {
        $rate = $order->shop->settings?->expected_shipping_rate;

        // No configured expectation → cannot assess → never guess (ADR 0022 §3/§5).
        if ($rate === null || $rate === []) {
            return null;
        }

        $tiers = $this->normalizeTiers($rate);

        if ($tiers === []) {
            return null;
        }

        $lines = $order->lines;

        // No weight basis to compute from → fail-safe, no expected fee.
        if ($lines->isEmpty()) {
            return null;
        }

        $orderChargeableG = 0;

        foreach ($lines as $line) {
            $variant = $line->variant;

            if ($variant === null) {
                return null;
            }

            $weight = $variant->package_weight_g;
            $length = $variant->package_length_mm;
            $width = $variant->package_width_mm;
            $height = $variant->package_height_mm;

            // A Variant without weight/dimensions yields no expected fee
            // (fail-safe — ADR 0022 §5). NO bundle expansion (deferred, §5).
            if ($weight === null || $length === null || $width === null || $height === null) {
                return null;
            }

            $volumetricG = intdiv($length * $width * $height, self::VOLUMETRIC_DIVISOR);
            $chargeablePerUnit = max($weight, $volumetricG);
            $orderChargeableG += $chargeablePerUnit * $line->qty;
        }

        // Tier lookup (ADR 0022 §3): the fee of the first tier whose up_to_g
        // covers the chargeable weight; if it exceeds every tier, the highest
        // tier's fee (conservative — a heavy parcel is never false-flagged).
        foreach ($tiers as $tier) {
            if ($orderChargeableG <= $tier['up_to_g']) {
                return Money::fromSatang($tier['fee']);
            }
        }

        return Money::fromSatang($tiers[array_key_last($tiers)]['fee']);
    }

    /**
     * Validate + ascending-sort the jsonb tier list. A malformed entry (missing
     * up_to_g/fee, or non-integer) fails loud — never silently mis-computes a
     * money input (ADR 0005 posture).
     *
     * @param  array<array-key, mixed>  $rate
     * @return list<array{up_to_g: int, fee: int}>
     */
    private function normalizeTiers(array $rate): array
    {
        $tiers = [];

        foreach ($rate as $entry) {
            if (! is_array($entry)
                || ! array_key_exists('up_to_g', $entry)
                || ! array_key_exists('fee', $entry)
                || ! is_int($entry['up_to_g'])
                || ! is_int($entry['fee'])) {
                throw new InvalidArgumentException(
                    'Malformed expected_shipping_rate tier: each entry needs an integer up_to_g (grams) and fee (satang).',
                );
            }

            $tiers[] = ['up_to_g' => $entry['up_to_g'], 'fee' => $entry['fee']];
        }

        usort($tiers, fn (array $a, array $b): int => $a['up_to_g'] <=> $b['up_to_g']);

        return $tiers;
    }
}
