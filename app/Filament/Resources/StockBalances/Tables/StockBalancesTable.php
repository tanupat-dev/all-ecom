<?php

namespace App\Filament\Resources\StockBalances\Tables;

use App\Models\StockBalance;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

class StockBalancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('variant.master_sku')
                    ->label('Master SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant.product.name')
                    ->label('สินค้า'),
                TextColumn::make('location.name')
                    ->label('คลัง/สาขา')
                    ->sortable(),
                TextColumn::make('on_hand')
                    ->label('On-Hand')
                    ->sortable(),
                TextColumn::make('reserved')
                    ->label('Reserved')
                    ->sortable(),
                TextColumn::make('buffer')
                    ->label('Buffer'),
                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (StockBalance $record): int => $record->available)
                    ->color(fn (StockBalance $record): ?string => $record->available < 0 ? 'danger' : null),
                TextColumn::make('damaged')
                    ->label('ชำรุด'),
            ])
            ->recordActions([
                // Quantities only move through the ledger; Buffer is the one
                // editable policy number here (CONTEXT.md: Buffer).
                Action::make('editBuffer')
                    ->label('ตั้ง Buffer')
                    ->schema([
                        TextInput::make('buffer')
                            ->label('Buffer (กันสต็อก)')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ])
                    ->fillForm(fn (StockBalance $record): array => ['buffer' => $record->buffer])
                    ->action(function (StockBalance $record, array $data): void {
                        $buffer = $data['buffer'] ?? null;

                        if (! is_numeric($buffer) || (int) $buffer < 0) {
                            throw new InvalidArgumentException('Buffer must be a non-negative integer.');
                        }

                        $record->update(['buffer' => (int) $buffer]);
                    }),
            ]);
    }
}
