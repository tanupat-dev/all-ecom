<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\TracksCreatedBy;
use App\Observers\ExpenseObserver;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An operating expense entered manually by the seller (CONTEXT.md: Expense,
 * Issue #69). Not a Cost Price and not a Platform fee — e.g. packaging, rent,
 * staff costs. Used in period-level (monthly) P&L.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property Carbon $date
 * @property string $category free-form string (packaging, rent, staff, …)
 * @property Money $amount positive integer satang (ADR 0015)
 * @property string|null $note
 * @property int|null $ref_order_id optional — for per-order attributable costs
 */
#[ObservedBy(ExpenseObserver::class)]
class Expense extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['date', 'category', 'amount', 'note', 'ref_order_id'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'date' => 'date',
        ];
    }

    /**
     * The optional Order this expense is attributable to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function refOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ref_order_id');
    }
}
