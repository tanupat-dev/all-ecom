<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\PlatformType;
use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One seller account on a Platform (CONTEXT.md: Shop), drawing stock from
 * its fulfilment Location. Marketplace Shops carry a 1:1 Shop Settings;
 * pos/social have none.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property Platform $platform
 * @property PlatformType $platform_type
 * @property int $location_id
 */
class Shop extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['name', 'platform', 'platform_type', 'location_id'];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'platform_type' => PlatformType::class,
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasOne<ShopSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(ShopSetting::class);
    }
}
