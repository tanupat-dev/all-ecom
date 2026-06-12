<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Actions\Listings\CreateListing as CreateListingAction;
use App\Filament\Resources\Listings\ListingResource;
use App\Models\Product;
use App\Models\Shop;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateListing extends CreateRecord
{
    protected static string $resource = ListingResource::class;

    /**
     * Creation goes through the CreateListing Action so every Variant is
     * mapped atomically with its Master SKU default and the conflict guard.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $shopId = $data['shop_id'] ?? null;
        $productId = $data['product_id'] ?? null;

        if (! is_numeric($shopId) || ! is_numeric($productId)) {
            throw new InvalidArgumentException('A Listing needs a Shop and a Product.');
        }

        return app(CreateListingAction::class)->handle(
            Shop::query()->findOrFail((int) $shopId),
            Product::query()->findOrFail((int) $productId),
        );
    }
}
