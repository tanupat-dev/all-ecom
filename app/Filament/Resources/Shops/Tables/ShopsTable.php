<?php

namespace App\Filament\Resources\Shops\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('ชื่อร้าน')->searchable(),
                TextColumn::make('platform')->label('แพลตฟอร์ม')->badge(),
                TextColumn::make('platform_type')->label('ประเภท')->badge(),
                TextColumn::make('location.name')->label('คลังที่ใช้ส่งของ'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
