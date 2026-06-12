<?php

namespace App\Filament\Resources\Orders;

use App\Actions\Returns\DeriveRefundStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only Order list (ADR 0002): marketplace Orders are mirrors of the
 * Platform; manual entry/POS flows arrive with Phases 3–4.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $modelLabel = 'ออเดอร์';

    protected static ?string $navigationLabel = 'ออเดอร์';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('#'),
            TextColumn::make('shop.name')->label('ร้าน'),
            TextColumn::make('platform_type')->label('ช่องทาง')->badge(),
            TextColumn::make('status')->label('สถานะ')->badge(),
            TextColumn::make('total')
                ->label('ยอดรวม (บาท)')
                ->state(fn (Order $record): string => $record->total?->toBaht() ?? '-'),
            TextColumn::make('created_date')->label('วันที่สั่ง')->dateTime()->sortable(),
            TextColumn::make('refund_status')
                ->label('สถานะคืนเงิน')
                ->badge()
                ->state(fn (Order $record): string => app(DeriveRefundStatus::class)->handle($record)->value)
                ->toggleable(isToggledHiddenByDefault: true),
            // CONTEXT.md: Cancellation Reason — the Seller Cancellation
            // Rate drilldown; raw platform text in the tooltip.
            TextColumn::make('cancelled_by')
                ->label('ยกเลิกโดย')
                ->badge()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('cancel_reason_category')
                ->label('เหตุผลยกเลิก')
                ->badge()
                ->tooltip(fn (Order $record): ?string => $record->cancel_reason_source)
                ->toggleable(isToggledHiddenByDefault: true),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }
}
