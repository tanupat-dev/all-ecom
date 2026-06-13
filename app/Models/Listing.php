<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Product placed on a marketplace Shop — the channel-side projection
 * layer (CONTEXT.md: Listing; ADR 0010). MVP scope: the Platform SKU
 * mapping + Deal Price, plus read-only reference fields from imports.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shop_id
 * @property int $product_id
 * @property string|null $category
 * @property string|null $image_url
 */
class Listing extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['shop_id', 'product_id', 'category', 'image_url'];

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Listing Variants in creation order (mirrors the Product's Variant
     * order). The explicit order is the contract — see Product::variants():
     * an unordered HasMany returns Postgres' unspecified heap order, which
     * drifts and makes the Filament table, channel export, and tests flaky.
     *
     * @return HasMany<ListingVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ListingVariant::class)->orderBy('id');
    }
}
