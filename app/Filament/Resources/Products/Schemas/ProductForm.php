<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Support\Money;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
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
                // Channel-agnostic listing fields (ADR 0019) — authored once,
                // shared across every Platform, feed the Channel Upload
                // Template fill. No per-channel content here.
                TextInput::make('english_name')
                    ->label('ชื่อภาษาอังกฤษ')
                    ->nullable()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('คำอธิบายสินค้า')
                    ->nullable()
                    ->rows(4),
                TextInput::make('brand')
                    ->label('แบรนด์')
                    ->nullable()
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
                        // Package dimensions — integers in grams / millimetres
                        // (ADR 0015 no-float rule; ADR 0019 channel-agnostic).
                        // Platform-unit conversion (kg, cm) is done at fill time.
                        TextInput::make('package_weight_g')
                            ->label('น้ำหนักพัสดุ (กรัม)')
                            ->nullable()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('package_width_mm')
                            ->label('กว้าง (มม.)')
                            ->nullable()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('package_length_mm')
                            ->label('ยาว (มม.)')
                            ->nullable()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('package_height_mm')
                            ->label('สูง (มม.)')
                            ->nullable()
                            ->integer()
                            ->minValue(0),
                    ]),
            ]);
    }
}
