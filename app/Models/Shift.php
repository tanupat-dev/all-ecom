<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ShiftStatus;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One Cashier's session at one Register — the unit of cash accountability
 * (CONTEXT.md: Shift). created_by = the Cashier who opened it and owns
 * its over/short. At most one open Shift per Register (partial unique).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $register_id
 * @property ShiftStatus $status
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property Money|null $opening_float
 * @property Money|null $counted_cash
 * @property Money|null $expected_cash
 * @property Money|null $over_short
 * @property int|null $created_by
 */
class Shift extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = [
        'register_id', 'status', 'opened_at', 'closed_at',
        'opening_float', 'counted_cash', 'expected_cash', 'over_short',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => MoneyCast::class,
            'counted_cash' => MoneyCast::class,
            'expected_cash' => MoneyCast::class,
            'over_short' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Register, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    /**
     * @return HasMany<ShiftCashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(ShiftCashMovement::class);
    }
}
