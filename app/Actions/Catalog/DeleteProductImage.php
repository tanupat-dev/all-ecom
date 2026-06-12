<?php

namespace App\Actions\Catalog;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a Product Image — removes the file from object storage and then
 * the DB row (ADR 0019, Issue #47). Both operations run in sequence; a
 * missing file is silently ignored so a retry after a partial failure doesn't
 * abort on the delete.
 */
class DeleteProductImage
{
    public function handle(ProductImage $image): void
    {
        $diskConfig = config('filesystems.product_images_disk', 'product-images');
        $diskName = is_string($diskConfig) ? $diskConfig : 'product-images';

        Storage::disk($diskName)->delete($image->path);

        $image->delete();
    }
}
