<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Actions\Shops\CreateShop as CreateShopAction;
use App\Enums\Platform;
use App\Filament\Resources\Shops\ShopResource;
use App\Models\Location;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreateShop extends CreateRecord
{
    protected static string $resource = ShopResource::class;

    /**
     * Creation goes through the CreateShop Action so platform_type is
     * derived and a marketplace Shop gets its Shop Settings atomically.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = $data['name'] ?? null;
        $platform = $data['platform'] ?? null;
        $locationId = $data['location_id'] ?? null;

        if (! is_string($name) || ! is_string($platform) || ! is_numeric($locationId)) {
            throw new InvalidArgumentException('A Shop needs a name, a platform, and a fulfilment Location.');
        }

        return app(CreateShopAction::class)->handle(
            $name,
            Platform::from($platform),
            Location::query()->findOrFail((int) $locationId),
        );
    }
}
