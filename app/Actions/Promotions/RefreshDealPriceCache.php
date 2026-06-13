<?php

namespace App\Actions\Promotions;

use App\Models\ListingVariant;

/**
 * Recomputes the Deal Price cache for one Listing-Variant (ADR 0021).
 * `ListingVariant.deal_price` is a write-through cache of the currently-active
 * Promotion Line's Deal Price — null when only the List Price applies. The
 * Promotion Line is the single source of truth (ResolveEffectivePrice); this
 * keeps the hot read (the Channel Upload Template export, the listing grid)
 * O(1) without scanning Promotions at request time.
 *
 * Called on every Promotion-Line write via PromotionLineObserver (one hook,
 * every path covered — mirrors the Daily P&L denormalization) and by the
 * promotions:refresh-cache command for the time-boundary case (a campaign
 * opening/closing with no edit, where no write fires).
 */
class RefreshDealPriceCache
{
    public function __construct(private ResolveEffectivePrice $resolveEffectivePrice) {}

    public function handle(ListingVariant $listingVariant): ListingVariant
    {
        // The cache reflects "now": the Deal Price of the active line, or null
        // when only the List Price applies (CONTEXT.md: Effective Price).
        $cache = $this->resolveEffectivePrice->activeLine($listingVariant)?->deal_price;

        $listingVariant->deal_price = $cache;
        $listingVariant->save();

        return $listingVariant;
    }
}
