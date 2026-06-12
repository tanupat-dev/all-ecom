<?php

namespace App\Filament\Resources\Listings\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shop.name')->label('ร้าน')->sortable(),
                TextColumn::make('product.name')->label('สินค้า')->searchable(),
                TextColumn::make('variants_count')->label('จำนวน SKU')->counts('variants'),
                TextColumn::make('created_at')->label('สร้างเมื่อ')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
