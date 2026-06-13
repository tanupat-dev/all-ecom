<?php

namespace App\Filament\Resources\Promotions\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PromotionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('ชื่อ')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('ประเภท')
                    ->badge()
                    ->sortable(),
                TextColumn::make('start_at')
                    ->label('เริ่ม')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('end_at')
                    ->label('สิ้นสุด')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
