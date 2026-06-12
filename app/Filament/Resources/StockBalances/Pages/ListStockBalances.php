<?php

namespace App\Filament\Resources\StockBalances\Pages;

use App\Actions\Imports\StartImport;
use App\Filament\Resources\StockBalances\StockBalanceResource;
use App\Imports\StockAdjustmentImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class ListStockBalances extends ListRecords
{
    protected static string $resource = StockBalanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importAdjustments')
                ->label('นำเข้าปรับสต็อก (Excel)')
                ->schema([
                    FileUpload::make('file')
                        ->label('ไฟล์ .xlsx (master_sku · location · action · qty)')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'] ?? null;

                    if (! $file instanceof UploadedFile) {
                        throw new InvalidArgumentException('Expected an uploaded .xlsx file.');
                    }

                    app(StartImport::class)->handle($file, StockAdjustmentImporter::class);
                }),
        ];
    }
}
