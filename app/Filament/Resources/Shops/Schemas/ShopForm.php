<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Enums\Platform;
use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ShopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ชื่อร้าน')
                    ->required()
                    ->maxLength(255),
                Select::make('platform')
                    ->label('แพลตฟอร์ม')
                    ->options(Platform::class)
                    ->required(),
                Select::make('location_id')
                    ->label('คลังที่ใช้ส่งของ (fulfilment Location)')
                    ->options(fn (): array => Location::query()->pluck('name', 'id')->all())
                    ->required(),
            ]);
    }
}
