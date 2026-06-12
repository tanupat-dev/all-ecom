<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Variant + qty within an Order (CONTEXT.md: Order Line) — the unit
 * that actually moves stock. A Bundle line moves its components' stock,
 * never the bundle's (ADR 0014).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $order_id
 * @property int $variant_id
 * @property int $qty
 * @property Money|null $unit_price
 * @property Money|null $line_total
 */
class OrderLine extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['order_id', 'variant_id', 'qty', 'unit_price', 'line_total'];

    protected function casts(): array
    {
        return [
            'unit_price' => MoneyCast::class,
            'line_total' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
