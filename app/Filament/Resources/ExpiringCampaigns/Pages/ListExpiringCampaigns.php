<?php

namespace App\Filament\Resources\ExpiringCampaigns\Pages;

use App\Filament\Resources\ExpiringCampaigns\ExpiringCampaignResource;
use Filament\Resources\Pages\ListRecords;

class ListExpiringCampaigns extends ListRecords
{
    protected static string $resource = ExpiringCampaignResource::class;
}
