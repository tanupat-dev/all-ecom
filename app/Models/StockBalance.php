<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The denormalized current quantities per (Variant, Location) — updated in
 * the SAME transaction as the Stock Movement append; never SUM() the ledger
 * at runtime (ROADMAP Phase-1 scaling rule 1). Buffer is a policy number,
 * not stock — changing it is not a ledger event.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $variant_id
 * @property int $location_id
 * @property int $on_hand
 * @property int $reserved
 * @property int $damaged
 * @property int $buffer
 * @property-read int $available
 */
class StockBalance extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['variant_id', 'location_id', 'on_hand', 'reserved', 'damaged', 'buffer'];

    /**
     * Available = On-Hand − Reserved − Buffer; may go negative — that is
     * the Oversell signal, clamped to 0 only on Platform export
     * (CONTEXT.md: Available Stock).
     *
     * @return Attribute<int, never>
     */
    protected function available(): Attribute
    {
        return Attribute::get(fn (): int => $this->on_hand - $this->reserved - $this->buffer);
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
}
