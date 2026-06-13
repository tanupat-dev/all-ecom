<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\Platform;
use App\Enums\ReturnReasonFault;
use App\Enums\ReturnSubStatus;
use App\Enums\ReturnType;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
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
 * @property ReturnReasonFault|null $reason_fault
 * @property string|null $buyer_note
 * @property Money|null $refund_amount
 * @property string|null $tracking_number
 * @property Carbon|null $requested_at
 * @property Carbon|null $refunded_at
 * @property bool $refunded
 */
class OrderReturn extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $table = 'returns';

    protected $fillable = [
        'shop_id', 'platform_return_id', 'ref_order_id', 'return_type', 'sub_status',
        'return_reason', 'reason_fault', 'buyer_note', 'refund_amount', 'tracking_number', 'requested_at', 'refunded_at', 'refunded',
    ];

    protected function casts(): array
    {
        return [
            'return_type' => ReturnType::class,
            'sub_status' => ReturnSubStatus::class,
            'reason_fault' => ReturnReasonFault::class,
            'refund_amount' => MoneyCast::class,
            'requested_at' => 'datetime',
            'refunded_at' => 'datetime',
            'refunded' => 'boolean',
        ];
    }

    /**
     * Returns stuck in รอผู้ซื้อส่งคืน past the Platform's buyer-ship
     * window (ADR 0006: the stale-Return flag) — counted from the request
     * time; platforms with an undocumented window never flag (ADR 0005).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStale(Builder $query): Builder
    {
        return $query
            ->where('sub_status', ReturnSubStatus::AwaitingBuyerShipment)
            ->where(function (Builder $query): void {
                $flagged = false;

                foreach (Platform::cases() as $platform) {
                    $days = $platform->buyerShipWindowDays();

                    if ($days === null) {
                        continue;
                    }

                    $flagged = true;
                    $query->orWhere(fn (Builder $q): Builder => $q
                        ->whereHas('shop', fn ($shop) => $shop->where('platform', $platform))
                        ->where('requested_at', '<', now()->subDays($days)));
                }

                if (! $flagged) {
                    $query->whereRaw('1 = 0');
                }
            });
    }

    public function isStale(): bool
    {
        $days = $this->shop()->firstOrFail()->platform->buyerShipWindowDays();

        return $days !== null
            && $this->sub_status === ReturnSubStatus::AwaitingBuyerShipment
            && $this->requested_at !== null
            && $this->requested_at->lt(now()->subDays($days));
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
