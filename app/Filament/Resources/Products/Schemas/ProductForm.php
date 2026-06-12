<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Support\Money;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('ชื่อสินค้า')
                    ->required()
                    ->maxLength(255),
                // The Variant is the sellable unit — a Product always has at
                // least one (CONTEXT.md: Variant).
                Repeater::make('variants')
                    ->label('ตัวเลือกสินค้า (Variant)')
                    ->relationship()
                    ->minItems(1)
                    ->defaultItems(1)
                    ->schema([
                        TextInput::make('master_sku')
                            ->label('Master SKU')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name')
                            ->label('ตัวเลือก (เช่น สี / ไซส์)')
                            ->maxLength(255),
                        TextInput::make('barcode')
                            ->label('บาร์โค้ด')
                            ->maxLength(255),
                        // Entered/displayed in baht; converted to integer
                        // satang at this boundary (ADR 0015).
                        TextInput::make('list_price')
                            ->label('List Price (บาท)')
                            ->required()
                            ->rule('regex:/^\d+(\.\d{1,2})?$/')
                            ->formatStateUsing(fn (Money|string|null $state): ?string => $state instanceof Money ? $state->toBaht() : $state)
                            ->dehydrateStateUsing(fn (?string $state): ?Money => $state !== null && $state !== '' ? Money::fromBaht($state) : null),
                    ]),
            ]);
    }
}
