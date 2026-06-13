<?php

namespace App\Filament\Resources\OverduePayouts;

use App\Enums\ReconciliationStatus;
use App\Filament\Resources\OverduePayouts\Pages\ListOverduePayouts;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Read-only Overdue Payout list (CONTEXT.md: Expected Payout Date; Issue
 * #67): marketplace Orders where `expected_payout_date` has passed AND
 * `reconciliation_status = not_yet_paid` — the Platform has not settled the
 * money and the claim window may be closing.
 *
 * Mirrors ReconciliationMismatchResource in structure — read-only, pinned
 * query, same `accounting.view` gate (ADR 0012). BelongsToTenant on Order
 * already scopes by tenant.
 */
class OverduePayoutResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $modelLabel = 'ออเดอร์เงินค้าง';

    protected static ?string $navigationLabel = 'เงินค้างเกินกำหนด';

    /**
     * Pin the Resource to overdue Orders: past their expected payout date
     * and still not settled (CONTEXT.md: Expected Payout Date).
     * BelongsToTenant on Order already scopes by tenant.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('expected_payout_date', '<', Date::now())
            ->where('reconciliation_status', ReconciliationStatus::NotYetPaid);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->checkPermissionTo('accounting.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->checkPermissionTo('accounting.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('shop.name')
                    ->label('ร้าน')
                    ->searchable(),
                TextColumn::make('platform_order_id')
                    ->label('เลขออเดอร์แพลตฟอร์ม')
                    ->searchable(),
                TextColumn::make('anchor_date')
                    ->label('วันที่ Anchor (milestone)')
                    ->state(fn (Order $record): string => self::anchorDate($record)),
                TextColumn::make('expected_payout_date')
                    ->label('วันคาดว่าเงินเข้า')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reconciliation_status')
                    ->label('สถานะตรวจสอบ')
                    ->badge(),
            ])
            ->defaultSort('expected_payout_date', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOverduePayouts::route('/'),
        ];
    }

    /**
     * Render the anchor milestone date for the given Order — reads the Shop's
     * `payout_anchor` setting to know which milestone column to show.
     */
    private static function anchorDate(Order $record): string
    {
        $anchor = $record->shop->settings?->payout_anchor;

        if ($anchor === null) {
            return '-';
        }

        /** @var Carbon|null $date */
        $date = $record->{$anchor};

        return $date?->toDateTimeString() ?? '-';
    }
}
