<?php

namespace App\Filament\Resources\OverduePayouts\Pages;

use App\Filament\Resources\OverduePayouts\OverduePayoutResource;
use Filament\Resources\Pages\ListRecords;

class ListOverduePayouts extends ListRecords
{
    protected static string $resource = OverduePayoutResource::class;
}
