<?php

namespace App\Filament\Resources\Claims;

use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Filament\Resources\Claims\Pages\ListClaims;
use App\Filament\Resources\Claims\Pages\ViewClaim;
use App\Filament\Resources\Claims\RelationManagers\EvidenceItemsRelationManager;
use App\Filament\Resources\Claims\RelationManagers\TimelineEntriesRelationManager;
use App\Models\Claim;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-mostly Claim back-office Resource (CONTEXT.md: Claim; Issue #84).
 * Claims are created automatically by FlagReturnFeeClaim / CreateClaim — not
 * by hand here. The seller uses this screen to track and advance each Claim
 * through its lifecycle, manage the evidence checklist, and log timeline
 * events. Authorization is handled entirely by ClaimPolicy (ADR 0012):
 * `claim.view` = read; `claim.manage` = all mutations.
 */
class ClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $modelLabel = 'เคลม';

    protected static ?string $navigationLabel = 'เคลม (Claims)';

    /**
     * Claims are created by the auto-flag pipeline, not hand-entered here.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The Claim table / infolist is read-only — mutations go through Actions.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Deleting a Claim is not a supported operation in this Resource.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('claim_type')
                    ->label('ประเภท')
                    ->badge(),
                TextColumn::make('status')
                    ->label('สถานะ')
                    ->badge(),
                TextColumn::make('order.platform_order_id')
                    ->label('ออเดอร์')
                    ->searchable(),
                TextColumn::make('orderReturn.id')
                    ->label('Return')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('สร้างเมื่อ')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('claim_type')
                    ->label('ประเภทเคลม')
                    ->options(ClaimType::class),
                SelectFilter::make('status')
                    ->label('สถานะ')
                    ->options(ClaimStatus::class),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('claim_type')
                ->label('ประเภทเคลม')
                ->badge(),
            TextEntry::make('status')
                ->label('สถานะ')
                ->badge(),
            TextEntry::make('order.platform_order_id')
                ->label('เลขออเดอร์'),
            TextEntry::make('order.shop.name')
                ->label('ร้าน'),
            // ── Return Reason block — return_fee Claims only ──────────────────
            // CONTEXT.md: Return Reason — auto-flag is an alert, not a verdict.
            TextEntry::make('orderReturn.return_reason')
                ->label('เหตุผลคืนสินค้า')
                ->helperText('auto-flag เป็นการเตือนให้ตรวจสอบ ไม่ใช่คำตัดสิน')
                ->visible(fn (Claim $record): bool => $record->claim_type === ClaimType::ReturnFee),
            TextEntry::make('orderReturn.buyer_note')
                ->label('หมายเหตุผู้ซื้อ')
                ->visible(fn (Claim $record): bool => $record->claim_type === ClaimType::ReturnFee),
            TextEntry::make('orderReturn.reason_fault')
                ->label('ฝ่ายผิด')
                ->badge()
                ->visible(fn (Claim $record): bool => $record->claim_type === ClaimType::ReturnFee),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            EvidenceItemsRelationManager::class,
            TimelineEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaims::route('/'),
            'view' => ViewClaim::route('/{record}'),
        ];
    }
}
