<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request the seller files with a Platform to recover money it (or its
 * courier) deducted incorrectly (CONTEXT.md: Claim; Issue #79). The system
 * scaffolds the work — it does not file the Claim. Always attaches to one
 * Order (ref_order_id, never null); a `return_fee` Claim additionally
 * attaches to the Return that triggered it (ref_return_id). No money lives
 * here — payout amounts are recorded on the Claim Timeline (later slice).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property ClaimType $claim_type
 * @property ClaimStatus $status
 * @property int $ref_order_id
 * @property int|null $ref_return_id
 */
class Claim extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = [
        'claim_type', 'status', 'ref_order_id', 'ref_return_id',
    ];

    protected function casts(): array
    {
        return [
            'claim_type' => ClaimType::class,
            'status' => ClaimStatus::class,
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
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'ref_return_id');
    }
}
