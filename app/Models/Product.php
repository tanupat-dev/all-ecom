<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A sellable item in the master catalog (CONTEXT.md: Product). The sellable
 * unit is the Variant — a Product always has at least one.
 *
 * Channel-agnostic listing fields (ADR 0019, Issue #46) — authored once,
 * shared across every Platform, feed the Channel Upload Template fill.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string|null $english_name
 * @property string|null $description
 * @property string|null $brand
 *
 * Relations:
 * @property-read Collection<int, Variant> $variants
 * @property-read Collection<int, ProductImage> $images
 */
class Product extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['name', 'english_name', 'description', 'brand'];

    /**
     * Variants in creation order (the order the seller entered them). The
     * explicit order is the contract — a HasMany without it returns rows in
     * Postgres' unspecified heap order, which drifts after deletes/rollbacks
     * and makes ordered reads (Filament display, channel export, tests)
     * non-deterministic. Mirrors images()->orderBy('sort_order').
     *
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class)->orderBy('id');
    }

    /**
     * All images for this product, ordered by sort_order ascending.
     * Channel-agnostic — shared across every Platform (ADR 0019, Issue #47).
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * The primary image — the one with the lowest sort_order — or null if
     * the product has no images yet.
     *
     * @return HasOne<ProductImage, $this>
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->orderBy('sort_order');
    }
}
