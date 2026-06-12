<?php

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Catalog\ExportCatalogueMaster;
use App\Actions\Imports\StartImport;
use App\Filament\Resources\Products\ProductResource;
use App\Imports\CatalogueMasterImporter;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Export catalogue master (ADR 0019 round-trip, Phase 9):
            // one row per Variant with all channel-agnostic listing fields.
            // Gated on product.view via ProductPolicy::exportCatalogueMaster.
            Action::make('exportCatalogueMaster')
                ->label('ส่งออก Catalogue Master (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn (): bool => auth()->user()?->can('exportCatalogueMaster', Product::class) ?? false)
                ->action(fn (): StreamedResponse => app(ExportCatalogueMaster::class)->download()),

            // Import catalogue master (ADR 0019 round-trip, Phase 9):
            // re-import the edited file to update listing fields by master_sku.
            // Gated on product.edit via ProductPolicy::importCatalogueMaster.
            Action::make('importCatalogueMaster')
                ->label('นำเข้า Catalogue Master (Excel)')
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize(fn (): bool => auth()->user()?->can('importCatalogueMaster', Product::class) ?? false)
                ->schema([
                    FileUpload::make('file')
                        ->label('ไฟล์ .xlsx (master_sku · product_name · english_name · description · brand · variant_name · package_*)')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'] ?? null;

                    if (! $file instanceof UploadedFile) {
                        throw new InvalidArgumentException('Expected an uploaded .xlsx file.');
                    }

                    $job = app(StartImport::class)->handle($file, CatalogueMasterImporter::class);

                    Notification::make()
                        ->title("รับไฟล์แล้ว — กำลังนำเข้า Catalogue Master (งาน #{$job->id})")
                        ->success()
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
