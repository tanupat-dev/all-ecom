<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Resources\Pages\EditRecord;

class EditListing extends EditRecord
{
    protected static string $resource = ListingResource::class;
}
