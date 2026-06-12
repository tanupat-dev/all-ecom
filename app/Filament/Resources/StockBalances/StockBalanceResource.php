<?php

namespace App\Filament\Resources\StockBalances;

use App\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Filament\Resources\StockBalances\Tables\StockBalancesTable;
use App\Models\StockBalance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only view of the denormalized balances — quantities only move by
 * appending Stock Movements (ADR 0003); Buffer is the one editable policy
 * number (a table action, not an edit page).
 */
class StockBalanceResource extends Resource
{
    protected static ?string $model = StockBalance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $modelLabel = 'สต็อก';

    protected static ?string $navigationLabel = 'สต็อก';

    public static function table(Table $table): Table
    {
        return StockBalancesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockBalances::route('/'),
        ];
    }
}
