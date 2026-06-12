<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-Shop marketplace money-flow configuration (CONTEXT.md: Shop
 * Settings) — a pos Shop has none.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shop_id
 * @property int $hold_period
 * @property string $payout_anchor
 * @property Money|null $mismatch_threshold
 * @property array<string, mixed>|null $expected_shipping_rate
 */
class ShopSetting extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['shop_id', 'hold_period', 'payout_anchor', 'mismatch_threshold', 'expected_shipping_rate'];

    protected function casts(): array
    {
        return [
            'mismatch_threshold' => MoneyCast::class,
            'expected_shipping_rate' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
