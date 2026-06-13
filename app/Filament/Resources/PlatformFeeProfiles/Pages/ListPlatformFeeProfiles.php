<?php

namespace App\Filament\Resources\PlatformFeeProfiles\Pages;

use App\Filament\Resources\PlatformFeeProfiles\PlatformFeeProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformFeeProfiles extends ListRecords
{
    protected static string $resource = PlatformFeeProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
