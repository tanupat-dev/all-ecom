<?php

namespace App\Actions\Pricing;

use App\Models\ListingVariant;
use App\Models\PlatformFeeProfile;
use App\Support\MarginTarget;
use App\Support\Money;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The Margin Calculator (CONTEXT.md: Margin Calculator; Issue #76), per
 * Listing-Variant — so it reads that Shop's Platform Fee Profile (different
 * Shops/Platforms have different fee structures → different recommended
 * prices). Two directions, both grounded in the SAME fee model as
 * ComputeExpectedNet (the forward fee application):
 *
 *   feeSatang(price) = Σ over the Shop's fee profiles of
 *                      intdiv(price * rate_bps + 5000, 10000) + fixed_satang
 *   net(price)       = price − feeSatang(price)
 *
 * All integer satang, never float (ADR 0015). If ComputeExpectedNet's fee
 * formula or rounding ever changes, this Action must change with it (they
 * are forward/inverse of one identical model) — keep them in lockstep.
 *
 * --- Direction 1: target profit → recommended Effective Price ---
 * `required_net = cost + target_profit`. We want the SMALLEST integer-satang
 * Effective Price whose realised net ≥ required_net — the recommendation must
 * achieve AT LEAST the target, never round against the seller. The fee model
 * has a proportional part (Σ rate_bps) and a fixed part (Σ fixed_satang), so
 * analytically
 *
 *   price ≈ ceil((required_net + Σ fixed_satang) * 10000 / (10000 − Σ rate_bps))
 *
 * but because each category's fee is rounded independently with the half-up
 * `+ 5000`, the realised net at the analytic price can differ by a few satang.
 * So we compute the analytic price, then adjust ±1 satang against the REALISED
 * net (the same feeSatang math) until we have the smallest price with
 * net ≥ required_net. Pinned by feeding the recommended price back through the
 * fee math.
 *
 * --- Direction 2: Effective Price → implied profit (symmetric) ---
 * `profit = price − feeSatang(price) − cost`, signed satang, same fee math.
 *
 * Cost is `Variant::costAt(now())` — bundle-aware (Σ component cost, ADR 0014).
 * A Variant with no Cost Price at now → fail-loud (LogicException naming the
 * SKU): margin is undefined without cost, never assumed zero (same posture as
 * ComputePosOrderNet).
 */
class ComputeMargin
{
    /**
     * Forward: the smallest Effective Price whose realised net ≥ cost + target.
     */
    public function recommendedPrice(ListingVariant $listingVariant, MarginTarget $target): Money
    {
        $cost = $this->cost($listingVariant);
        $profiles = $this->feeProfiles($listingVariant);

        $requiredNet = $cost->satang + $target->resolve($cost)->satang;

        $sumBps = $profiles->reduce(
            static fn (int $carry, PlatformFeeProfile $profile): int => $carry + $profile->rate_bps,
            0,
        );
        $sumFixed = $profiles->reduce(
            static fn (int $carry, PlatformFeeProfile $profile): int => $carry + $profile->fixed_satang,
            0,
        );

        $denominator = 10000 - $sumBps;

        if ($denominator <= 0) {
            throw new LogicException(
                "The Shop's fee rate is ≥ 100% — no Effective Price can yield the target profit (Margin Calculator)."
            );
        }

        // Analytic ceiling, then ±1-satang adjustment against the realised net.
        $price = $this->ceilDiv(($requiredNet + $sumFixed) * 10000, $denominator);

        // Walk up if the half-up fee rounding left us a satang short.
        while ($this->netAt($price, $profiles) < $requiredNet) {
            $price++;
        }

        // Walk down to the smallest price that still meets the target (the
        // half-up rounding can hand the seller a free satang).
        while ($price > 0 && $this->netAt($price - 1, $profiles) >= $requiredNet) {
            $price--;
        }

        return Money::fromSatang($price);
    }

    /**
     * Symmetric: the signed implied profit for a given Effective Price.
     */
    public function impliedProfit(ListingVariant $listingVariant, Money $effectivePrice): Money
    {
        $cost = $this->cost($listingVariant);
        $profiles = $this->feeProfiles($listingVariant);

        return Money::fromSatang($this->netAt($effectivePrice->satang, $profiles) - $cost->satang);
    }

    /**
     * The realised net for a price = price − Σ fees, using EXACTLY
     * ComputeExpectedNet's per-category half-up fee math.
     *
     * @param  Collection<int, PlatformFeeProfile>  $profiles
     */
    private function netAt(int $priceSatang, Collection $profiles): int
    {
        $fees = 0;

        foreach ($profiles as $profile) {
            $fees += intdiv($priceSatang * $profile->rate_bps + 5000, 10000) + $profile->fixed_satang;
        }

        return $priceSatang - $fees;
    }

    /** The Variant's cost at now — bundle-aware; fail-loud if absent. */
    private function cost(ListingVariant $listingVariant): Money
    {
        $variant = $listingVariant->variant()->firstOrFail();

        return $variant->costAt(now())
            ?? throw new LogicException(
                "Variant [{$variant->master_sku}] has no Cost Price at now — set a Cost Price so the margin is known (it is never assumed zero)."
            );
    }

    /**
     * The current tenant's fee profiles for this Listing-Variant's Shop. The
     * BelongsToTenant scope + Postgres RLS keep this to the current tenant.
     *
     * @return Collection<int, PlatformFeeProfile>
     */
    private function feeProfiles(ListingVariant $listingVariant): Collection
    {
        return PlatformFeeProfile::query()
            ->where('shop_id', $listingVariant->shop_id)
            ->get();
    }

    /** Exact ceiling of $numerator / $denominator for non-negative integers. */
    private function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
