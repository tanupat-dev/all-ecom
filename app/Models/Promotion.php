<?php

namespace App\Models;

use App\Enums\PromotionType;
use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A pricing rule that lowers the Effective Price of one or more Variants on one
 * or more Listings (CONTEXT.md: Promotion; ADR 0021). Either a `base` markdown
 * (no window, ≤1 active per Shop) or a time-bounded `campaign` (start_at/end_at).
 *
 * A Promotion carries NO shop_id — its Shop scope is the set of Shops its
 * Promotion Lines touch (one Promotion may span Shops; CONTEXT.md).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property PromotionType $type
 * @property Carbon|null $start_at
 * @property Carbon|null $end_at
 */
class Promotion extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['name', 'type', 'start_at', 'end_at'];

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PromotionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PromotionLine::class);
    }
}
