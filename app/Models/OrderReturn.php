<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A buyer-initiated return case attached to an Order — header + lines,
 * never an Order Status (CONTEXT.md: Return; ADR 0006). Named OrderReturn
 * (the platforms' "Return Order") because `Return` is a PHP reserved word.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shop_id
 * @property string $platform_return_id
 * @property int $ref_order_id
 * @property ReturnType $return_type
 * @property ReturnSubStatus $sub_status
 * @property string|null $return_reason
 * @property string|null $buyer_note
 * @property Money|null $refund_amount
 * @property string|null $tracking_number
 * @property Carbon|null $requested_at
 */
class OrderReturn extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $table = 'returns';

    protected $fillable = [
        'shop_id', 'platform_return_id', 'ref_order_id', 'return_type', 'sub_status',
        'return_reason', 'buyer_note', 'refund_amount', 'tracking_number', 'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'return_type' => ReturnType::class,
            'sub_status' => ReturnSubStatus::class,
            'refund_amount' => MoneyCast::class,
            'requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ref_order_id');
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasMany<ReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class, 'return_id');
    }
}
