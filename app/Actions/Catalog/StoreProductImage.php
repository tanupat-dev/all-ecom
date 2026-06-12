<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Validates, normalises, and stores a Product Image (ADR 0019, Issue #47).
 *
 * Normalisation:
 *  - GD-decodable check is the upload-security gate: re-encoding through GD
 *    strips any embedded payload or EXIF data, so only clean pixel data
 *    reaches storage.
 *  - Pads to square 1:1 with a white background — never crops. All three
 *    Platforms require or strongly prefer a 1:1 image for bulk upload.
 *  - Re-encodes as JPEG at ~quality 90.
 *
 * Storage: written to tenants/{tenant_id}/product-images/{uuid}.jpg on the
 * disk named by PRODUCT_IMAGES_DISK (default "product-images"). The disk is
 * a local public disk by default; production swaps to an R2/S3 disk (#48).
 */
class StoreProductImage
{
    public function handle(Product $product, UploadedFile $file, ?Variant $variant = null): ProductImage
    {
        // ── 1. Validate: GD must be able to decode the file ─────────────────
        $fileContents = file_get_contents($file->getRealPath());

        if ($fileContents === false) {
            throw new RuntimeException('Could not read the uploaded file.');
        }

        $source = @imagecreatefromstring($fileContents);

        if (! ($source instanceof GdImage)) {
            throw new InvalidArgumentException(
                'The uploaded file is not a valid image (cannot be decoded by GD). Upload rejected.'
            );
        }

        // ── 2. Normalise: pad to square 1:1 with white background ────────────
        $originalWidth = imagesx($source);
        $originalHeight = imagesy($source);
        $size = max($originalWidth, $originalHeight);

        $canvas = imagecreatetruecolor($size, $size);

        if (! ($canvas instanceof GdImage)) {
            imagedestroy($source);
            throw new RuntimeException('GD failed to create the canvas — check GD memory limits.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);

        if ($white === false) {
            imagedestroy($source);
            imagedestroy($canvas);
            throw new RuntimeException('GD failed to allocate the white background colour.');
        }

        imagefill($canvas, 0, 0, $white);

        // Centre the original within the square. Alpha blending is on by
        // default, so transparent pixels (PNG) blend with the white canvas.
        $offsetX = (int) (($size - $originalWidth) / 2);
        $offsetY = (int) (($size - $originalHeight) / 2);

        imagecopy($canvas, $source, $offsetX, $offsetY, 0, 0, $originalWidth, $originalHeight);
        imagedestroy($source);

        // ── 3. Re-encode as JPEG quality 90 ─────────────────────────────────
        ob_start();
        imagejpeg($canvas, null, 90);
        $rawOutput = ob_get_clean();
        imagedestroy($canvas);

        if ($rawOutput === false || $rawOutput === '') {
            throw new RuntimeException('GD failed to encode the image as JPEG.');
        }

        $jpeg = $rawOutput;

        // ── 4. Store under a tenant-scoped randomised path ───────────────────
        $tenantId = $product->tenant_id;

        if ($tenantId === null) {
            throw new RuntimeException('Product has no tenant_id — cannot store image.');
        }

        $filename = Str::uuid().'.jpg';
        $path = "tenants/{$tenantId}/product-images/{$filename}";

        $diskConfig = config('filesystems.product_images_disk', 'product-images');
        $diskName = is_string($diskConfig) ? $diskConfig : 'product-images';

        Storage::disk($diskName)->put($path, $jpeg, 'public');

        // ── 5. Determine next sort_order (append to end) ─────────────────────
        // Builder::max() returns mixed; narrow to int before arithmetic.
        $lastOrder = ProductImage::query()
            ->where('product_id', $product->id)
            ->max('sort_order');

        if (is_int($lastOrder)) {
            $nextOrder = $lastOrder + 1;
        } elseif (is_numeric($lastOrder)) {
            $nextOrder = (int) $lastOrder + 1;
        } else {
            $nextOrder = 0;
        }

        // ── 6. Persist the DB row ─────────────────────────────────────────────
        return ProductImage::query()->create([
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'path' => $path,
            'sort_order' => $nextOrder,
        ]);
    }
}
