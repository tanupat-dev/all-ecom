<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One row of a Variant's cost history (CONVENTIONS rule 9): profit uses
 * the cost AT THE SALE DATE, so cost changes append rows with valid_from —
 * never overwrite.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $variant_id
 * @property Money|null $cost
 * @property Carbon $valid_from
 */
class CostPrice extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['variant_id', 'cost', 'valid_from'];

    protected function casts(): array
    {
        return [
            'cost' => MoneyCast::class,
            'valid_from' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Cost Price is history (CONVENTIONS rule 9) — append a new row, never update.');
        });

        static::deleting(function (): never {
            throw new LogicException('Cost Price is history (CONVENTIONS rule 9) — never delete a row.');
        });
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
