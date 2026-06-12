<?php

namespace App\Models;

use App\Enums\StockAction;
use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One immutable ledger entry (CONTEXT.md: Stock Movement, ADR 0003):
 * never updated or deleted — corrections append new entries. qty_delta is
 * the signed change to the action's primary pool; a SHIP row additionally
 * records reserved_released — the reservation that order actually held
 * (marketplace = line qty, POS = 0), making the ledger fully replayable.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $variant_id
 * @property int $location_id
 * @property StockAction $action
 * @property int $qty_delta
 * @property int|null $reserved_released
 * @property string|null $note
 */
class StockMovement extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['variant_id', 'location_id', 'action', 'qty_delta', 'reserved_released', 'ref_type', 'ref_id', 'note'];

    protected function casts(): array
    {
        return [
            'action' => StockAction::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Stock Movements are an immutable ledger (ADR 0003) — never update; append a correction.');
        });

        static::deleting(function (): never {
            throw new LogicException('Stock Movements are an immutable ledger (ADR 0003) — never delete; append a correction.');
        });
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function ref(): MorphTo
    {
        return $this->morphTo();
    }
}
