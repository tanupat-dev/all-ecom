<?php

namespace App\Filament\Resources\Roles;

use App\Authorization\PermissionCatalogue;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Tenant-defined Roles composed from the system Permission catalogue
 * (ADR 0012). Deletion goes through the DeleteRole Action (strip + the
 * lock-out safeguard) on the edit page.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $modelLabel = 'บทบาท';

    protected static ?string $navigationLabel = 'บทบาท (Role)';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('ชื่อบทบาท')
                ->required()
                ->maxLength(255),
            CheckboxList::make('permissions')
                ->label('สิทธิ์ (Permission)')
                ->relationship('permissions', 'name')
                ->options(array_combine(PermissionCatalogue::ALL, PermissionCatalogue::ALL))
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('ชื่อบทบาท')->searchable(),
            TextColumn::make('permissions_count')->label('จำนวนสิทธิ์')->counts('permissions'),
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('role.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
