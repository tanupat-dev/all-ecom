<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Enums\PlatformType;
use App\Models\Product;
use App\Models\Shop;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class ListingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->label('ร้าน (marketplace เท่านั้น)')
                    ->options(fn (): array => Shop::query()
                        ->where('platform_type', PlatformType::Marketplace)
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->disabledOn('edit'),
                Select::make('product_id')
                    ->label('สินค้า')
                    ->options(fn (): array => Product::query()->pluck('name', 'id')->all())
                    ->required()
                    ->disabledOn('edit'),
            ]);
    }
}
