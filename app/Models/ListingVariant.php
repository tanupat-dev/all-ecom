<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Platform SKU mapping row: (Shop, Platform SKU) → Variant
 * (CONTEXT.md: Platform SKU). shop_id is denormalized from the listing so
 * the importer lookup hits one index.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $listing_id
 * @property int $shop_id
 * @property int $variant_id
 * @property string $platform_sku
 * @property Money|null $deal_price
 */
class ListingVariant extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['listing_id', 'shop_id', 'variant_id', 'platform_sku', 'deal_price'];

    protected function casts(): array
    {
        return [
            'deal_price' => MoneyCast::class,
        ];
    }

    /**
     * Rows that would break the resolution function (CONTEXT.md: Platform
     * SKU): the same (Shop, Platform SKU) pointing at a different Variant.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConflictingWith(Builder $query, int $shopId, string $platformSku, int $variantId): Builder
    {
        return $query
            ->where('shop_id', $shopId)
            ->where('platform_sku', $platformSku)
            ->where('variant_id', '!=', $variantId);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
