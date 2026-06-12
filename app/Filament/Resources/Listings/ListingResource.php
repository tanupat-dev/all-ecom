<?php

namespace App\Filament\Resources\Listings;

use App\Filament\Resources\Listings\Pages\CreateListing;
use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Listings\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\Listings\Schemas\ListingForm;
use App\Filament\Resources\Listings\Tables\ListingsTable;
use App\Models\Listing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'รายการขาย';

    protected static ?string $navigationLabel = 'รายการขาย (Listing)';

    public static function form(Schema $schema): Schema
    {
        return ListingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ListingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VariantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
            'create' => CreateListing::route('/create'),
            'edit' => EditListing::route('/{record}/edit'),
        ];
    }
}
