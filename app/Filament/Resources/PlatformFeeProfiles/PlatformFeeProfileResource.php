<?php

namespace App\Filament\Resources\PlatformFeeProfiles;

use App\Filament\Resources\PlatformFeeProfiles\Pages\CreatePlatformFeeProfile;
use App\Filament\Resources\PlatformFeeProfiles\Pages\EditPlatformFeeProfile;
use App\Filament\Resources\PlatformFeeProfiles\Pages\ListPlatformFeeProfiles;
use App\Filament\Resources\PlatformFeeProfiles\Schemas\PlatformFeeProfileForm;
use App\Filament\Resources\PlatformFeeProfiles\Tables\PlatformFeeProfilesTable;
use App\Models\PlatformFeeProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlatformFeeProfileResource extends Resource
{
    protected static ?string $model = PlatformFeeProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $modelLabel = 'อัตราค่าธรรมเนียม';

    protected static ?string $navigationLabel = 'อัตราค่าธรรมเนียม (Fee Profile)';

    public static function form(Schema $schema): Schema
    {
        return PlatformFeeProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformFeeProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformFeeProfiles::route('/'),
            'create' => CreatePlatformFeeProfile::route('/create'),
            'edit' => EditPlatformFeeProfile::route('/{record}/edit'),
        ];
    }
}
