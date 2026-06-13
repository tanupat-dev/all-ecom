<?php

namespace App\Filament\Resources\UnclassifiedReturns;

use App\Enums\ReturnReasonFault;
use App\Filament\Resources\UnclassifiedReturns\Pages\ListUnclassifiedReturns;
use App\Models\OrderReturn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only "Unclassified Return Reasons" alert list (Issue #78;
 * CONTEXT.md: Return Reason). Shows Returns whose `return_reason` is
 * non-null but whose `reason_fault` is null — i.e. the importer could
 * not map the reason to a fault bucket (ADR 0005 fail-loud). The seller
 * classifies these manually via the inline action.
 *
 * Mirrors ExpiringCampaignResource / OverduePayoutResource in shape:
 * read-only list, `getEloquentQuery()` pinned to the alert condition,
 * gated on `return.view` for reading and `return.manage` for classifying
 * (ADR 0012). BelongsToTenant on OrderReturn scopes by tenant automatically.
 *
 * ⚠️ Limitation: TikTok pre-shipment cancellation reasons (CONTEXT.md:
 * Return Reason — skip, not a return) are also stored with null reason_fault
 * and may appear in this list if they end up on a Return record. The importer
 * pipeline typically does not create Return records for pre-shipment
 * cancellations, but if it does, the seller will see them here. Adding a
 * dedicated `is_preshipment_skip` flag to exclude them is deferred.
 */
class UnclassifiedReturnResource extends Resource
{
    protected static ?string $model = OrderReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $modelLabel = 'เหตุผลคืนที่ยังไม่จัดประเภท';

    protected static ?string $navigationLabel = 'เหตุผลคืนสินค้าที่ยังไม่จัดประเภท';

    /**
     * Pin the Resource to Returns that have a reason but no fault bucket:
     * – return_reason IS NOT NULL  (the importer captured a reason text)
     * – reason_fault IS NULL        (the text was not in the known list)
     *
     * BelongsToTenant on OrderReturn already scopes by tenant.
     *
     * @return Builder<Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('return_reason')
            ->whereNull('reason_fault');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->checkPermissionTo('return.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->checkPermissionTo('return.view') ?? false;
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
                TextColumn::make('platform_return_id')
                    ->label('เลขเคสคืน')
                    ->searchable(),
                TextColumn::make('shop.name')
                    ->label('ร้าน')
                    ->searchable(),
                TextColumn::make('return_reason')
                    ->label('เหตุผลที่แพลตฟอร์มส่งมา')
                    ->tooltip(fn (OrderReturn $record): ?string => $record->buyer_note)
                    ->limit(50),
                TextColumn::make('requested_at')
                    ->label('ยื่นคำขอเมื่อ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->recordActions([
                Action::make('classify')
                    ->label('จัดประเภท')
                    ->icon('heroicon-o-tag')
                    ->authorize(fn (OrderReturn $record): bool => auth()->user()?->checkPermissionTo('return.manage') ?? false)
                    ->schema([
                        Select::make('reason_fault')
                            ->label('ประเภทความรับผิดชอบ')
                            ->options([
                                ReturnReasonFault::BuyerFault->value => 'ผู้ซื้อรับผิดชอบ (buyer fault)',
                                ReturnReasonFault::SellerFault->value => 'ผู้ขายรับผิดชอบ (seller fault)',
                            ])
                            ->required(),
                    ])
                    ->action(function (OrderReturn $record, array $data): void {
                        $value = $data['reason_fault'];

                        if (! is_string($value)) {
                            return;
                        }

                        $record->update(['reason_fault' => ReturnReasonFault::from($value)]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnclassifiedReturns::route('/'),
        ];
    }
}
