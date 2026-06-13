<?php

namespace App\Filament\Resources\ReconciliationMismatches;

use App\Enums\ReconciliationStatus;
use App\Filament\Resources\ReconciliationMismatches\Pages\ListReconciliationMismatches;
use App\Filament\Resources\ReconciliationMismatches\Pages\ViewReconciliationMismatch;
use App\Filament\Resources\ReconciliationMismatches\RelationManagers\AccountingLinesRelationManager;
use App\Models\Order;
use App\Support\Money;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only Reconciliation Mismatch list (CONTEXT.md: Reconciliation Status;
 * ADR 0007, Issue #66): the marketplace Orders where Actual Net diverged from
 * Expected Net beyond the Shop's Mismatch Threshold (`paid_mismatch`). It is a
 * second, narrowly scoped Resource over the Order model — the query is pinned
 * to PaidMismatch and is tenant-scoped via BelongsToTenant on Order. Drilldown
 * to the Order's Accounting Entry lines (by `source_field`) is the relation
 * view on the record page. Gated on `accounting.view` (ADR 0012) — the same
 * permission that reads the Accounting Entry lines — NOT `order.view`.
 *
 * Claim auto-suggestion is Phase 8 and out of scope here.
 */
class ReconciliationMismatchResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $modelLabel = 'รายการไม่ตรง';

    protected static ?string $navigationLabel = 'ยอดไม่ตรง (Mismatch)';

    /**
     * Pin the whole Resource to mismatched Orders. BelongsToTenant on Order
     * already scopes by tenant, so a mismatch Order of another tenant is never
     * reachable here.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('reconciliation_status', ReconciliationStatus::PaidMismatch);
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
                TextColumn::make('expected_net')
                    ->label('คาดว่าได้รับ (บาท)')
                    ->state(fn (Order $record): string => $record->expected_net?->toBaht() ?? '-'),
                TextColumn::make('actual_net')
                    ->label('ได้รับจริง (บาท)')
                    ->state(fn (Order $record): string => $record->actual_net?->toBaht() ?? '-'),
                TextColumn::make('difference')
                    ->label('ส่วนต่าง (บาท)')
                    ->state(fn (Order $record): string => self::differenceBaht($record)),
                TextColumn::make('settlement_date')
                    ->label('วันที่ปิดยอด')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('platform_order_id')->label('เลขออเดอร์แพลตฟอร์ม'),
            TextEntry::make('shop.name')->label('ร้าน'),
            TextEntry::make('expected_net')
                ->label('คาดว่าได้รับ (บาท)')
                ->state(fn (Order $record): string => $record->expected_net?->toBaht() ?? '-'),
            TextEntry::make('actual_net')
                ->label('ได้รับจริง (บาท)')
                ->state(fn (Order $record): string => $record->actual_net?->toBaht() ?? '-'),
            TextEntry::make('difference')
                ->label('ส่วนต่าง (บาท)')
                ->state(fn (Order $record): string => self::differenceBaht($record)),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            AccountingLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReconciliationMismatches::route('/'),
            'view' => ViewReconciliationMismatch::route('/{record}'),
        ];
    }

    /**
     * Signed satang difference (Actual − Expected) rendered in baht — never a
     * float (ADR 0015); the sign shows whether the Platform paid short or over.
     */
    private static function differenceBaht(Order $record): string
    {
        $actual = $record->actual_net?->satang;
        $expected = $record->expected_net?->satang;

        if ($actual === null || $expected === null) {
            return '-';
        }

        return Money::fromSatang($actual - $expected)->toBaht();
    }
}
