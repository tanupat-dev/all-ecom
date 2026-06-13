<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CancelledBy;
use App\Enums\CancelReasonCategory;
use App\Enums\OrderStatus;
use App\Enums\PlatformType;
use App\Enums\ReturnSubStatus;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The unified Order across all platform types (ADR 0002) with flat
 * milestone timestamps (ADR 0004). Marketplace Orders are read-only
 * mirrors of the Platform; only social/pos Orders are manually editable,
 * and only Pre-Pack.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shop_id
 * @property PlatformType $platform_type
 * @property string|null $platform_order_id
 * @property OrderStatus $status
 * @property Money|null $total
 * @property string|null $tracking_number
 * @property string|null $buyer_name
 * @property string|null $buyer_phone
 * @property int|null $shift_id
 * @property int|null $receipt_no
 * @property Money|null $cart_discount
 * @property int|null $ref_order_id
 * @property CancelledBy|null $cancelled_by
 * @property CancelReasonCategory|null $cancel_reason_category
 * @property string|null $cancel_reason_source
 * @property ReturnSubStatus|null $return_sub_status
 * @property Money|null $actual_net
 * @property Carbon|null $settlement_date
 * @property Carbon|null $created_date
 * @property Carbon|null $paid_date
 * @property Carbon|null $shipped_date
 * @property Carbon|null $delivered_date
 * @property Carbon|null $completed_date
 * @property Carbon|null $cancelled_date
 */
class Order extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    /** The 6 flat milestone fields of ADR 0004. */
    public const MILESTONES = [
        'created_date',
        'paid_date',
        'shipped_date',
        'delivered_date',
        'completed_date',
        'cancelled_date',
    ];

    protected $fillable = [
        'shop_id', 'platform_type', 'platform_order_id', 'status', 'total',
        'tracking_number', 'buyer_name', 'buyer_phone',
        'shift_id', 'receipt_no', 'cart_discount', 'ref_order_id',
        'cancelled_by', 'cancel_reason_category', 'cancel_reason_source', 'return_sub_status',
        'actual_net', 'settlement_date',
        'created_date', 'paid_date', 'shipped_date', 'delivered_date', 'completed_date', 'cancelled_date',
    ];

    protected function casts(): array
    {
        return [
            'platform_type' => PlatformType::class,
            'status' => OrderStatus::class,
            'cancelled_by' => CancelledBy::class,
            'cancel_reason_category' => CancelReasonCategory::class,
            'return_sub_status' => ReturnSubStatus::class,
            'total' => MoneyCast::class,
            'cart_discount' => MoneyCast::class,
            'actual_net' => MoneyCast::class,
            'settlement_date' => 'datetime',
            'created_date' => 'datetime',
            'paid_date' => 'datetime',
            'shipped_date' => 'datetime',
            'delivered_date' => 'datetime',
            'completed_date' => 'datetime',
            'cancelled_date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<OrderReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class, 'ref_order_id');
    }

    /**
     * @return HasMany<AccountingEntryLine, $this>
     */
    public function accountingEntryLines(): HasMany
    {
        return $this->hasMany(AccountingEntryLine::class);
    }

    /**
     * Pre-Pack = no Tracking Number yet (CONTEXT.md: Tracking Number) —
     * the window where lines may still be edited/cancelled.
     */
    public function isPrePack(): bool
    {
        return $this->tracking_number === null;
    }
}
