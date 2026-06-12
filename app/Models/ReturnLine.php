<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which Order Line and how many units are coming back (CONTEXT.md:
 * Return; ADR 0006). Stock Return credits per Return Line × qty, never
 * the whole Order.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $return_id
 * @property int $ref_order_line_id
 * @property int $qty
 */
class ReturnLine extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['return_id', 'ref_order_line_id', 'qty'];

    /**
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'ref_order_line_id');
    }
}
