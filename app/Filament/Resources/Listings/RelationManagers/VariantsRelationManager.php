<?php

namespace App\Filament\Resources\Listings\RelationManagers;

use App\Actions\Listings\UpdateListingVariant;
use App\Models\ListingVariant;
use App\Support\Money;
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
                TextColumn::make('deal_price')
                    ->label('Deal Price (฿)')
                    ->formatStateUsing(fn (Money|string|null $state): ?string => $state instanceof Money ? $state->toBaht() : $state),
                // Listing Status badge — read-only display (CONTEXT.md: Listing
                // Status). Transitions are handled by later slices (#60).
                TextColumn::make('listing_status')
                    ->label('สถานะ')
                    ->badge(),
            ])
            // Mapping rows are auto-provisioned by CreateListing — the
            // seller only overrides, never adds or removes rows here.
            ->headerActions([])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        TextInput::make('platform_sku')
                            ->label('Platform SKU')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('deal_price')
                            ->label('Deal Price (฿)')
                            ->numeric()
                            ->formatStateUsing(fn (Money|string|null $state): ?string => $state instanceof Money ? $state->toBaht() : $state),
                    ])
                    // The override goes through the Action so the
                    // (Shop, Platform SKU) → Variant function stays intact.
                    ->using(function (ListingVariant $record, array $data): ListingVariant {
                        $platformSku = $data['platform_sku'] ?? null;

                        if (! is_string($platformSku) || $platformSku === '') {
                            throw new InvalidArgumentException('A mapping needs a Platform SKU.');
                        }

                        $dealPrice = $data['deal_price'] ?? null;

                        return app(UpdateListingVariant::class)->handle(
                            $record,
                            $platformSku,
                            is_string($dealPrice) && $dealPrice !== '' ? Money::fromBaht($dealPrice) : null,
                        );
                    }),
            ]);
    }
}
