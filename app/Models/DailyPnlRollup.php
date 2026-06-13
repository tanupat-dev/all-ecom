<?php

namespace App\Models;

use App\Models\Concerns\TracksCreatedBy;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The queue-maintained Daily P&L rollup row (CONTEXT.md: Accounting Entry —
 * the combined P&L; Issue #71). One row per (tenant_id, shop_id, date); the
 * row always equals a from-raw recomputation (RecomputeDailyPnl) and never
 * double-counts a re-import (ADR 0007). Reports read this — they never SUM
 * the raw ledger at runtime (ROADMAP Phase-1 scaling rule).
 *
 * `date` is the Asia/Bangkok calendar date of the sale (see the migration).
 * All money columns are integer satang (ADR 0015).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int $shop_id
 * @property Carbon $date
 * @property int $marketplace_actual_net satang
 * @property array<string, int> $fee_breakdown AccountingLineCategory value → satang
 * @property int $pos_revenue satang
 * @property int $pos_cogs satang
 * @property int $pos_net satang
 * @property int $uncosted_pos_orders POS Orders excluded for missing Cost Price
 * @property int $expense_total satang
 * @property int $cash_over_short satang, signed (shortage −, overage +)
 */
class DailyPnlRollup extends Model
{
    use BelongsToTenant;
    use TracksCreatedBy;

    protected $fillable = [
        'shop_id', 'date',
        'marketplace_actual_net', 'fee_breakdown',
        'pos_revenue', 'pos_cogs', 'pos_net', 'uncosted_pos_orders',
        'expense_total', 'cash_over_short',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'marketplace_actual_net' => 'integer',
            'fee_breakdown' => 'array',
            'pos_revenue' => 'integer',
            'pos_cogs' => 'integer',
            'pos_net' => 'integer',
            'uncosted_pos_orders' => 'integer',
            'expense_total' => 'integer',
            'cash_over_short' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
