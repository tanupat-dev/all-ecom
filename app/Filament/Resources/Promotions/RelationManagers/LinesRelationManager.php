<?php

namespace App\Filament\Resources\Promotions\RelationManagers;

use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Promotion Lines for a Promotion (CONTEXT.md: Promotion Line). Lines are
 * provisioned through the CreatePromotion Action (which enforces the
 * Promotion's invariants), so this surface shows them and lets the seller
 * adjust a line's Deal Price — entered in baht, stored as integer satang
 * (ADR 0015). It never adds or removes rows here.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'รายการโปรโมชั่น (Promotion Lines)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('listingVariant.variant.master_sku')->label('Master SKU'),
                TextColumn::make('listingVariant.platform_sku')->label('Platform SKU')->searchable(),
                TextColumn::make('deal_price')
                    ->label('Deal Price (฿)')
                    ->formatStateUsing(fn (Money|string|null $state): ?string => $state instanceof Money ? $state->toBaht() : $state),
            ])
            ->headerActions([])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        TextInput::make('deal_price')
                            ->label('Deal Price (฿)')
                            ->required()
                            ->rule('regex:/^\d+(\.\d{1,2})?$/')
                            ->formatStateUsing(fn (Money|string|null $state): ?string => $state instanceof Money ? $state->toBaht() : $state)
                            ->dehydrateStateUsing(fn (?string $state): ?Money => $state !== null && $state !== '' ? Money::fromBaht($state) : null),
                    ]),
            ]);
    }
}
