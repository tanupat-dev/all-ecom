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
     * @param  list<array{master_sku: string, list_price: Money, name?: string|null, barcode?: string|null}>  $variants
     */
    public function handle(string $name, array $variants): Product
    {
        if ($variants === []) {
            throw new InvalidArgumentException('A Product needs at least one Variant — the Variant is the sellable unit.');
        }

        return DB::transaction(function () use ($name, $variants): Product {
            $product = Product::query()->create(['name' => $name]);

            foreach ($variants as $variant) {
                $product->variants()->create([
                    'master_sku' => $variant['master_sku'],
                    'name' => $variant['name'] ?? null,
                    'barcode' => $variant['barcode'] ?? null,
                    'list_price' => $variant['list_price'],
                ]);
            }

            return $product->load('variants');
        });
    }
}
