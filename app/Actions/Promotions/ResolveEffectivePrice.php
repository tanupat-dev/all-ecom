<?php

namespace App\Actions\Promotions;

use App\Enums\PromotionType;
use App\Models\ListingVariant;
use App\Models\PromotionLine;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The authority for a Listing-Variant's Effective Price at a moment T
 * (CONTEXT.md: Effective Price; ADR 0021). ListingVariant.deal_price is only a
 * denormalized cache of this Action's result — this Action is always exact.
 *
 * Resolution chain (CONTEXT.md):
 *   active campaign Promotion Line at T → its Deal Price
 *   else the base Promotion Line       → its Deal Price
 *   else the Variant's List Price.
 *
 * Window semantics: a campaign is active over the half-open interval
 * **[start_at, end_at)** — inclusive start, exclusive end. So a campaign is
 * active at T iff `start_at <= T < end_at`; at the instant of end_at it is no
 * longer active (which is what lets back-to-back campaigns touch at a boundary
 * without overlapping). The MVP invariant (exactly one active Promotion Line at
 * T) makes the campaign lookup return at most one row (CreatePromotion enforces
 * no overlapping campaigns per Listing-Variant).
 *
 * All amounts are integer satang (ADR 0015); a percent-off is already converted
 * to satang at the PromotionLineInput boundary, never resolved as a rate here.
 */
class ResolveEffectivePrice
{
    public function handle(ListingVariant $listingVariant, ?CarbonInterface $at = null): Money
    {
        $line = $this->activeLine($listingVariant, $at);

        if ($line !== null) {
            return $line->deal_price;
        }

        $listPrice = $listingVariant->variant()->firstOrFail()->list_price;

        if ($listPrice === null) {
            // Fail loud — never silently default an unmapped price (ADR 0005).
            throw new InvalidArgumentException(
                "Cannot resolve an Effective Price: Variant [{$listingVariant->variant_id}] has no List Price and no Promotion Line applies."
            );
        }

        return $listPrice;
    }

    /**
     * The Promotion Line that governs the Effective Price at T (campaign > base),
     * or null when only the List Price applies. This is also the cache source:
     * RefreshDealPriceCache stores this line's Deal Price (null = List Price).
     */
    public function activeLine(ListingVariant $listingVariant, ?CarbonInterface $at = null): ?PromotionLine
    {
        $at ??= now();

        $campaign = PromotionLine::query()
            ->where('listing_variant_id', $listingVariant->id)
            ->whereHas('promotion', function (Builder $query) use ($at): void {
                $query->where('type', PromotionType::Campaign->value)
                    ->where('start_at', '<=', $at)
                    ->where('end_at', '>', $at);
            })
            ->first();

        if ($campaign !== null) {
            return $campaign;
        }

        return PromotionLine::query()
            ->where('listing_variant_id', $listingVariant->id)
            ->whereHas('promotion', function (Builder $query): void {
                $query->where('type', PromotionType::Base->value);
            })
            ->first();
    }
}
