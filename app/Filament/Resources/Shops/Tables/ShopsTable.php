<?php

namespace App\Filament\Resources\Shops\Tables;

use App\Actions\Imports\StartImport;
use App\Actions\Stock\ExportShopStock;
use App\Enums\PlatformType;
use App\Models\Shop;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                Action::make('importOrders')
                    ->label('นำเข้าออเดอร์')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (Shop $record): bool => $record->platform->orderImporter() !== null)
                    ->authorize(fn (Shop $record): bool => auth()->user()?->can('importOrders', $record) ?? false)
                    ->schema([
                        FileUpload::make('file')
                            ->label('ไฟล์ออเดอร์จากแพลตฟอร์ม (.xlsx)')
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (Shop $record, array $data): void {
                        $file = $data['file'] ?? null;
                        $importer = $record->platform->orderImporter();

                        if (! $file instanceof UploadedFile || $importer === null) {
                            throw new InvalidArgumentException('An order import needs the platform export file.');
                        }

                        $job = app(StartImport::class)->handle($file, $importer, ['shop_id' => $record->id]);

                        Notification::make()
                            ->title("รับไฟล์แล้ว — กำลังนำเข้า (งาน #{$job->id})")
                            ->success()
                            ->send();
                    }),
                // Generic (platform_sku, qty) file for MVP — the exact
                // per-platform upload template follows once `ref doc/` is
                // restored (#37).
                Action::make('exportStock')
                    ->label('ส่งออกสต็อก')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Shop $record): bool => $record->platform_type === PlatformType::Marketplace)
                    ->authorize(fn (Shop $record): bool => auth()->user()?->can('exportStock', $record) ?? false)
                    ->action(fn (Shop $record): StreamedResponse => app(ExportShopStock::class)->download($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
