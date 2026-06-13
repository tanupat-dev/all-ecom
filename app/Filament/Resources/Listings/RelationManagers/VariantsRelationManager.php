<?php

namespace App\Filament\Resources\Listings\RelationManagers;

use App\Actions\Listings\ConfirmListingUpload;
use App\Actions\Listings\UpdateListingVariant;
use App\Enums\ListingStatus;
use App\Models\ListingVariant;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Platform SKU';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('variant.master_sku')->label('Master SKU'),
                TextColumn::make('variant.name')->label('ตัวเลือก'),
                TextColumn::make('platform_sku')->label('Platform SKU')->searchable(),
                // Read-only display of the Deal Price cache (ADR 0021): the
                // active Promotion Line's Deal Price, set by the Promotion
                // machinery (RefreshDealPriceCache) — never edited here.
                TextColumn::make('deal_price')
                    ->label('Deal Price (฿)')
                    ->formatStateUsing(fn (Money|string|null $state): ?string => $state instanceof Money ? $state->toBaht() : $state),
                // Listing Status badge — CONTEXT.md: Listing Status; ADR 0019.
                // Transitions from draft → listed via the confirmUpload action.
                TextColumn::make('listing_status')
                    ->label('สถานะ')
                    ->badge(),
            ])
            // Mapping rows are auto-provisioned by CreateListing — the
            // seller only overrides, never adds or removes rows here.
            ->headerActions([])
            ->recordActions([
                // Confirm upload: draft → listed (CONTEXT.md: Listing Status;
                // Issue #60). Visible only on draft rows so it cannot be used
                // as an unintended back-transition. Gated on listing.manage
                // via ListingVariantPolicy::update (ADR 0012).
                Action::make('confirmUpload')
                    ->label('ยืนยันว่าลงแล้ว')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ListingVariant $record): bool => $record->listing_status === ListingStatus::Draft)
                    ->authorize(fn (ListingVariant $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('ยืนยันว่าลงสินค้าบนแพลตฟอร์มแล้ว')
                    ->modalDescription('สถานะจะเปลี่ยนจาก "ร่าง" เป็น "ลงแล้ว" เมื่อยืนยัน ไม่สามารถย้อนกลับได้')
                    ->action(function (ListingVariant $record): void {
                        app(ConfirmListingUpload::class)->handle($record);
                    }),
                // Only the Platform SKU mapping is editable here. Deal Price is
                // not — it is the cache of the active Promotion Line (ADR 0021),
                // managed through the Promotion screens, not this row.
                EditAction::make()
                    ->schema([
                        TextInput::make('platform_sku')
                            ->label('Platform SKU')
                            ->required()
                            ->maxLength(255),
                    ])
                    // The override goes through the Action so the
                    // (Shop, Platform SKU) → Variant function stays intact.
                    ->using(function (ListingVariant $record, array $data): ListingVariant {
                        $platformSku = $data['platform_sku'] ?? null;

                        if (! is_string($platformSku) || $platformSku === '') {
                            throw new InvalidArgumentException('A mapping needs a Platform SKU.');
                        }

                        return app(UpdateListingVariant::class)->handle($record, $platformSku);
                    }),
            ]);
    }
}
