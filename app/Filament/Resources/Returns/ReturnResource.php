<?php

namespace App\Filament\Resources\Returns;

use App\Filament\Resources\Returns\Pages\ListReturns;
use App\Models\OrderReturn;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only Return list (ADR 0006): returns enter via import and move via
 * re-import or Inbound Scan — never manual edits.
 */
class ReturnResource extends Resource
{
    protected static ?string $model = OrderReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $modelLabel = 'การคืนสินค้า';

    protected static ?string $navigationLabel = 'คืนสินค้า (Return)';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('platform_return_id')->label('เลขเคสคืน')->searchable(),
            TextColumn::make('order.platform_order_id')->label('ออเดอร์'),
            TextColumn::make('shop.name')->label('ร้าน'),
            TextColumn::make('return_type')->label('ประเภท')->badge(),
            TextColumn::make('sub_status')->label('สถานะการคืน')->badge(),
            TextColumn::make('refund_amount')
                ->label('ยอดคืน (บาท)')
                ->state(fn (OrderReturn $record): string => $record->refund_amount?->toBaht() ?? '-'),
            TextColumn::make('return_reason')->label('เหตุผล')
                ->tooltip(fn (OrderReturn $record): ?string => $record->buyer_note)
                ->limit(30),
            TextColumn::make('requested_at')->label('ยื่นคำขอเมื่อ')->dateTime()->sortable(),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReturns::route('/'),
        ];
    }
}
