<?php

namespace App\Filament\Resources\PlatformFeeProfiles\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlatformFeeProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shop.name')
                    ->label('ร้าน')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('หมวดค่าธรรมเนียม')
                    ->sortable(),
                TextColumn::make('rate_bps')
                    ->label('อัตรา (bps)')
                    ->sortable(),
                TextColumn::make('fixed_satang')
                    ->label('คงที่ (สตางค์)')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
