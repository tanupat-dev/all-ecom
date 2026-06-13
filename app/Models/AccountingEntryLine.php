<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\FeeCategory;
use App\Models\Concerns\TracksCreatedBy;
use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One financial line item of an Order's Accounting Entry (CONTEXT.md:
 * Accounting Entry; ADR 0007): one Platform-native fee field (source_field)
 * mapped to one Fee Category, with a signed satang amount (+ = seller
 * receives, − = seller pays) and the statement_cycle it came from.
 * Marketplace Orders only — a POS Order never carries accounting lines.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $order_id
 * @property string $statement_cycle
 * @property string $source_field
 * @property FeeCategory $category
 * @property Money $amount
 */
class AccountingEntryLine extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = ['order_id', 'statement_cycle', 'source_field', 'category', 'amount'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'category' => FeeCategory::class,
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
