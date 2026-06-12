<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('ชื่อสินค้า')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variants_count')
                    ->label('จำนวนตัวเลือก')
                    ->counts('variants'),
                TextColumn::make('created_at')
                    ->label('สร้างเมื่อ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
