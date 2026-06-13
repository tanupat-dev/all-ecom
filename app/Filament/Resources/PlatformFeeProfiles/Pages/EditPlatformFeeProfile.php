<?php

namespace App\Filament\Resources\PlatformFeeProfiles\Pages;

use App\Filament\Resources\PlatformFeeProfiles\PlatformFeeProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlatformFeeProfile extends EditRecord
{
    protected static string $resource = PlatformFeeProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
