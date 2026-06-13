<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label('วันที่')
                    ->date()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('หมวดหมู่')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('จำนวนเงิน (บาท)')
                    ->formatStateUsing(fn (Money $state): string => $state->toBaht())
                    ->sortable(),
                TextColumn::make('note')
                    ->label('หมายเหตุ')
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('refOrder.platform_order_id')
                    ->label('ออเดอร์อ้างอิง')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('สร้างเมื่อ')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
