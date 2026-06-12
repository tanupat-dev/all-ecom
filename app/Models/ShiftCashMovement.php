<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CashMovementType;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One Paid-in / Paid-out on a Shift (CONTEXT.md: Shift) — a lightweight
 * append-only cash ledger, distinct from Stock Movements.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shift_id
 * @property CashMovementType $type
 * @property Money|null $amount
 * @property string $reason
 */
class ShiftCashMovement extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['shift_id', 'type', 'amount', 'reason'];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Shift cash movements are append-only (ledger pattern) — never update.');
        });

        static::deleting(function (): never {
            throw new LogicException('Shift cash movements are append-only (ledger pattern) — never delete.');
        });
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
