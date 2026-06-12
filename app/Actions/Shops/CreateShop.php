<?php

namespace App\Actions\Shops;

use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\Location;
use App\Models\Shop;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Shop with its fulfilment Location; a marketplace Shop gets its
 * Shop Settings in the same transaction with platform-correct defaults
 * (payout anchor per ADR 0004; ฿1 mismatch threshold per CONTEXT.md).
 */
class CreateShop
{
    public function handle(string $name, Platform $platform, Location $fulfilmentLocation): Shop
    {
        return DB::transaction(function () use ($name, $platform, $fulfilmentLocation): Shop {
            $shop = Shop::query()->create([
                'name' => $name,
                'platform' => $platform,
                'platform_type' => $platform->type(),
                'location_id' => $fulfilmentLocation->id,
            ]);

            if ($platform->type() === PlatformType::Marketplace) {
                $shop->settings()->create([
                    'hold_period' => 7,
                    'payout_anchor' => $platform->payoutAnchor(),
                    'mismatch_threshold' => Money::fromBaht('1'),
                    'expected_shipping_rate' => null,
                ]);
            }

            return $shop->load('settings');
        });
    }
}
