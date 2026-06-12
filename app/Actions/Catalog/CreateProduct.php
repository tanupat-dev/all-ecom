<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a Product with its Variants atomically. The Variant is the
 * sellable unit, so a Product is never created without one — a product
 * with no real options gets its single default Variant (CONTEXT.md:
 * Variant).
 */
class CreateProduct
{
    /**
     * @param  list<array{master_sku: string, list_price: Money, name?: string|null, barcode?: string|null, package_weight_g?: int|null, package_width_mm?: int|null, package_length_mm?: int|null, package_height_mm?: int|null}>  $variants
     * @param  array{english_name?: string|null, description?: string|null, brand?: string|null}  $meta
     */
    public function handle(string $name, array $variants, array $meta = []): Product
    {
        if ($variants === []) {
            throw new InvalidArgumentException('A Product needs at least one Variant — the Variant is the sellable unit.');
        }

        return DB::transaction(function () use ($name, $variants, $meta): Product {
            $product = Product::query()->create([
                'name' => $name,
                'english_name' => $meta['english_name'] ?? null,
                'description' => $meta['description'] ?? null,
                'brand' => $meta['brand'] ?? null,
            ]);

            foreach ($variants as $variant) {
                $product->variants()->create([
                    'master_sku' => $variant['master_sku'],
                    'name' => $variant['name'] ?? null,
                    'barcode' => $variant['barcode'] ?? null,
                    'list_price' => $variant['list_price'],
                    'package_weight_g' => $variant['package_weight_g'] ?? null,
                    'package_width_mm' => $variant['package_width_mm'] ?? null,
                    'package_length_mm' => $variant['package_length_mm'] ?? null,
                    'package_height_mm' => $variant['package_height_mm'] ?? null,
                ]);
            }

            return $product->load('variants');
        });
    }
}
