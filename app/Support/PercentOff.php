<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A "% off List Price" discount held as an integer count of basis points
 * (1% = 100 bps), never float (ADR 0015) — mirrors PlatformFeeProfile.rate_bps.
 *
 * The seller may enter a Deal Price as a percentage; this converts it to a
 * satang Money against a Variant's List Price at the input boundary so a rate
 * is never persisted (CONTEXT.md: Deal Price; ADR 0021).
 *
 * Rounding policy: the resulting Deal Price rounds UP to a whole satang, so the
 * realised discount is never DEEPER than the seller asked (a deeper discount
 * would be a lower Deal Price). e.g. 10% off ฿99.99 → ฿89.991 → stored ฿90.00.
 */
final readonly class PercentOff
{
    private function __construct(
        public int $basisPoints,
    ) {}

    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new InvalidArgumentException("Percent-off must be between 0 and 100% (0–10000 bps); got {$basisPoints} bps.");
        }

        return new self($basisPoints);
    }

    /**
     * Parse a decimal-string percentage ("15", "15.5", "33.33") into integer
     * basis points without ever passing through float (ADR 0015).
     */
    public static function fromPercent(string $percent): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $percent, $m) !== 1) {
            throw new InvalidArgumentException("Not a valid percentage: \"{$percent}\"");
        }

        $basisPoints = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return self::fromBasisPoints($basisPoints);
    }

    /**
     * Apply this discount to a List Price, returning the Deal Price as a Money.
     * Integer arithmetic throughout; rounds the Deal Price UP to a whole satang
     * (discount never deeper than asked).
     */
    public function applyTo(Money $listPrice): Money
    {
        if ($listPrice->isNegative()) {
            throw new InvalidArgumentException('Cannot apply a percent-off to a negative List Price.');
        }

        $remaining = $listPrice->satang * (10000 - $this->basisPoints);

        // ceil(remaining / 10000) for a non-negative numerator.
        return Money::fromSatang(intdiv($remaining + 9999, 10000));
    }
}
