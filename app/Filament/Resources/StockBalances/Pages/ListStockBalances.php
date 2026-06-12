<?php

namespace App\Filament\Resources\StockBalances\Pages;

use App\Filament\Resources\StockBalances\StockBalanceResource;
use Filament\Resources\Pages\ListRecords;

class ListStockBalances extends ListRecords
{
    protected static string $resource = StockBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
