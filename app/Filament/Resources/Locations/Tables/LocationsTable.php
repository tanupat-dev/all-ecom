<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Models\Location;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('ชื่อคลัง/สาขา')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('คลังหลัก')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('สร้างเมื่อ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                // The default Location is never deletable (ADR 0013) — the
                // model guard also enforces this below the UI.
                DeleteAction::make()
                    ->hidden(fn (Location $record): bool => $record->is_default),
            ]);
    }
}
