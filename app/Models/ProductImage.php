<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A product photo stored by all-ecom, normalised to a square (1:1) JPEG
 * (CONTEXT.md: Product Image; ADR 0019, Issue #47).
 *
 * Images are channel-agnostic — one set per Product/Variant, shared across
 * every Platform. They are reused by the Channel Upload Template fill engine
 * (#57–59) to write public URLs into image columns, so the raw path is
 * resolved through the configured disk (PRODUCT_IMAGES_DISK, default
 * "product-images") — never a hard-coded host.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string $path
 * @property int $sort_order
 * @property-read string $url
 */
class ProductImage extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['product_id', 'variant_id', 'path', 'sort_order'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * Public URL for the stored image, resolved through the configured disk
     * (PRODUCT_IMAGES_DISK env → config('filesystems.product_images_disk')).
     * No hard-coded host — production simply points the env at an R2 disk (#48).
     */
    public function getUrlAttribute(): string
    {
        $diskConfig = config('filesystems.product_images_disk', 'product-images');
        $disk = is_string($diskConfig) ? $diskConfig : 'product-images';

        return Storage::disk($disk)->url($this->path);
    }
}
