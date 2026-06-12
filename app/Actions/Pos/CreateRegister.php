<?php

namespace App\Actions\Pos;

use App\Enums\PlatformType;
use App\Models\Register;
use App\Models\Shop;
use InvalidArgumentException;

/**
 * Adds a Register (counter/till) to a pos Shop (CONTEXT.md: Register).
 */
class CreateRegister
{
    public function handle(Shop $shop, string $name, bool $active = true): Register
    {
        if ($shop->platform_type !== PlatformType::Pos) {
            throw new InvalidArgumentException('A Register belongs to a pos Shop only.');
        }

        return Register::query()->create([
            'shop_id' => $shop->id,
            'name' => $name,
            'active' => $active,
        ]);
    }
}
