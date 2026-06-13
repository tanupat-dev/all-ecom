<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Observers\PromotionLineObserver;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single row of a Promotion: one Variant on one Listing (Variant × Shop),
 * carrying its own Deal Price (CONTEXT.md: Promotion Line). The authority for
 * the Listing-Variant's Effective Price — ListingVariant.deal_price is a
 * denormalized cache of the active line (ADR 0021), kept consistent on every
 * write by PromotionLineObserver → RefreshDealPriceCache (#74).
 *
 * The line's Shop is its listing_variant's denormalized shop_id; the
 * base-per-Shop invariant is evaluated through it (CreatePromotion).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $promotion_id
 * @property int $listing_variant_id
 * @property Money $deal_price
 */
#[ObservedBy(PromotionLineObserver::class)]
class PromotionLine extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['promotion_id', 'listing_variant_id', 'deal_price'];

    protected function casts(): array
    {
        return [
            'deal_price' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return BelongsTo<ListingVariant, $this>
     */
    public function listingVariant(): BelongsTo
    {
        return $this->belongsTo(ListingVariant::class);
    }
}
