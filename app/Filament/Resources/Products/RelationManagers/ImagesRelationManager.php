<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\Catalog\DeleteProductImage;
use App\Actions\Catalog\StoreProductImage;
use App\Models\Product;
use App\Models\ProductImage;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Filament relation manager for Product Images (ADR 0019, Issue #47).
 *
 * Every upload is routed through StoreProductImage (normalisation + security
 * re-encoding must not be bypassable). Every delete goes through
 * DeleteProductImage (removes file + row atomically). Filament's own file
 * storage is bypassed (storeFiles: false) so the action owns the whole path.
 */
class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'รูปสินค้า';

    public function table(Table $table): Table
    {
        $diskConfig = config('filesystems.product_images_disk', 'product-images');
        $diskName = is_string($diskConfig) ? $diskConfig : 'product-images';

        return $table
            ->columns([
                ImageColumn::make('path')
                    ->label('รูปสินค้า')
                    ->disk($diskName)
                    ->square()
                    ->height(80),
                TextColumn::make('sort_order')
                    ->label('ลำดับ')
                    ->sortable(),
                TextColumn::make('variant.master_sku')
                    ->label('Variant (ถ้ามี)')
                    ->placeholder('—'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                // Upload goes through StoreProductImage — do NOT use
                // CreateAction (it would bypass normalisation/validation).
                Action::make('upload')
                    ->label('อัพโหลดรูปสินค้า')
                    ->icon('heroicon-o-arrow-up-tray')
                    // ADR 0012: gate on product.edit via ProductPolicy::update.
                    ->authorize(function (): bool {
                        /** @var Product $owner */
                        $owner = $this->getOwnerRecord();

                        return auth()->user()?->can('update', $owner) ?? false;
                    })
                    ->schema([
                        FileUpload::make('file')
                            ->label('รูปสินค้า (JPEG / PNG / WebP / GIF / BMP, สูงสุด 10 MB)')
                            // Bypass Filament's own storage — the Action
                            // handles normalisation and persistence itself.
                            ->storeFiles(false)
                            ->image()
                            ->maxSize(10 * 1024)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $file = $data['file'] ?? null;

                        if (! $file instanceof UploadedFile) {
                            throw new InvalidArgumentException('กรุณาเลือกไฟล์ภาพ');
                        }

                        /** @var Product $owner */
                        $owner = $this->getOwnerRecord();

                        app(StoreProductImage::class)->handle($owner, $file);

                        Notification::make()
                            ->title('อัพโหลดรูปสำเร็จ')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('ลบรูป')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    // ADR 0012: gate on product.edit via ProductPolicy::update.
                    ->authorize(function (ProductImage $record): bool {
                        /** @var Product $owner */
                        $owner = $this->getOwnerRecord();

                        return auth()->user()?->can('update', $owner) ?? false;
                    })
                    ->action(function (ProductImage $record): void {
                        app(DeleteProductImage::class)->handle($record);
                    }),
            ]);
    }
}
