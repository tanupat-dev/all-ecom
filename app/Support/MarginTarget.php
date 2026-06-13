<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The seller's target profit for the Margin Calculator (CONTEXT.md: Margin
 * Calculator) — EITHER a percent of cost OR a fixed THB amount. A value object
 * so the calculator takes one typed input and never juggles two nullable args.
 *
 * Integer satang throughout (ADR 0015): the percent is held in basis points
 * (321 = 3.21%, mirroring PlatformFeeProfile::$rate_bps), so resolving a
 * percent target is exact integer arithmetic — no float ever appears.
 */
final readonly class MarginTarget
{
    private function __construct(
        private ?int $percentBps,
        private ?Money $fixedProfit,
    ) {}

    /**
     * Target = $percentBps basis points of cost (3000 = 30% of cost).
     */
    public static function percentOfCost(int $percentBps): self
    {
        if ($percentBps < 0) {
            throw new InvalidArgumentException('A target profit percent cannot be negative.');
        }

        return new self($percentBps, null);
    }

    /**
     * Target = a fixed THB profit, regardless of cost.
     */
    public static function fixed(Money $profit): self
    {
        return new self(null, $profit);
    }

    /**
     * The target profit, in satang, for a given cost.
     *
     * A percent target is `ceil(cost * bps / 10000)` — rounded UP so the
     * realised target is never SHORT of what the seller asked for (the
     * sub-satang rounding goes in the seller's favour, consistent with the
     * recommendation never rounding against the seller). cost is non-negative,
     * so `+ 9999` before the integer divide is an exact ceiling.
     */
    public function resolve(Money $cost): Money
    {
        if ($this->fixedProfit !== null) {
            return $this->fixedProfit;
        }

        $bps = $this->percentBps ?? 0;

        return Money::fromSatang(intdiv($cost->satang * $bps + 9999, 10000));
    }
}
