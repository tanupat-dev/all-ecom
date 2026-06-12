<?php

namespace App\Filament\Resources\Shops\Tables;

use App\Actions\Stock\ExportShopStock;
use App\Enums\PlatformType;
use App\Models\Shop;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
