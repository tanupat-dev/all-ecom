<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TenderType;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Payment Line of a POS Order (CONTEXT.md: Payment) — split tender =
 * several lines. POS-only; marketplace money lives in Accounting Entries.
 * A negative amount is a refund line (POS Return, ADR 0009).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $order_id
 * @property TenderType $tender_type
 * @property Money|null $amount
 */
class Payment extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['order_id', 'tender_type', 'amount'];

    protected function casts(): array
    {
        return [
            'tender_type' => TenderType::class,
            'amount' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
