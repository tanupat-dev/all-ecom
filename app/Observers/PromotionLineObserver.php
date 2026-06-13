<?php

namespace App\Observers;

use App\Actions\Promotions\RefreshDealPriceCache;
use App\Models\PromotionLine;

/**
 * Keeps ListingVariant.deal_price (the Deal Price cache, ADR 0021) consistent
 * on every Promotion-Line write path — CreatePromotion, the Filament line-edit,
 * any future delete. Mirrors the Daily P&L observer rationale: one hook fires on
 * every write into promotion_lines, so the cache never drifts from the authority
 * (ResolveEffectivePrice) regardless of which path mutated the line — with zero
 * blast radius on the create/guard Action.
 *
 * The refresh runs synchronously (not queued) and within the writing
 * transaction, so a read immediately after the write sees the consistent cache.
 * The time-boundary case (a campaign opening/closing with no write) cannot fire
 * an observer — promotions:refresh-cache handles that on a schedule.
 */
class PromotionLineObserver
{
    public function saved(PromotionLine $line): void
    {
        $this->refresh($line);
    }

    public function deleted(PromotionLine $line): void
    {
        $this->refresh($line);
    }

    private function refresh(PromotionLine $line): void
    {
        $listingVariant = $line->listingVariant;

        if ($listingVariant !== null) {
            app(RefreshDealPriceCache::class)->handle($listingVariant);
        }
    }
}
