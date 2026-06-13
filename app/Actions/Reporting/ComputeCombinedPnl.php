<?php

namespace App\Actions\Reporting;

use App\Enums\PlatformType;
use App\Models\DailyPnlRollup;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Aggregates the Combined P&L for the current tenant across all channels over
 * the given date range (Issue #72).
 *
 * Reads from DailyPnlRollup only — never scans the raw order/movement ledger
 * (ROADMAP Phase-1 scaling rule). Expenses are the one exception: the
 * rollup's `expense_total` captures only per-order-attributable costs; most
 * operating expenses (rent, staff, utilities) carry no `ref_order_id` and are
 * excluded from the per-shop daily rollup. For the combined P&L we therefore
 * sum ALL tenant Expenses in the period directly from the Expense table —
 * this is safe because Expenses are low-volume (dozens/month), not the
 * million-row order ledger that prompted the "never SUM at runtime" rule.
 *
 * $canViewCost (ADR 0012): when false, COGS and profit columns are null.
 * All money is integer satang (ADR 0015); the view edge calls toBaht().
 *
 * @return array{
 *   marketplace_net: int,
 *   fee_breakdown: array<string, int>,
 *   pos_revenue: int,
 *   pos_cogs: int|null,
 *   pos_net: int|null,
 *   operating_expenses: int,
 *   cash_over_short: int,
 *   combined_net: int|null,
 *   uncosted_pos_orders: int,
 *   can_view_cost: bool,
 * }
 */
class ComputeCombinedPnl
{
    /**
     * @return array{
     *   marketplace_net: int,
     *   fee_breakdown: array<string, int>,
     *   pos_revenue: int,
     *   pos_cogs: int|null,
     *   pos_net: int|null,
     *   operating_expenses: int,
     *   cash_over_short: int,
     *   combined_net: int|null,
     *   uncosted_pos_orders: int,
     *   can_view_cost: bool,
     * }
     */
    public function handle(Carbon $from, Carbon $to, bool $canViewCost): array
    {
        // Load rollup rows in range; BelongsToTenant scope already filters
        // to the current tenant (ADR 0011).
        /** @var Collection<int, DailyPnlRollup> $rollups */
        $rollups = DailyPnlRollup::query()
            ->with('shop')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $marketplaceNet = 0;
        /** @var array<string, int> $feeBreakdown */
        $feeBreakdown = [];
        $posRevenue = 0;
        $posCogs = 0;
        $posNet = 0;
        $uncostedPosOrders = 0;
        $cashOverShort = 0;

        foreach ($rollups as $rollup) {
            $shop = $rollup->shop;

            if ($shop === null) {
                continue;
            }

            // cash_over_short and uncosted_pos_orders apply to all shop types
            $cashOverShort += $rollup->cash_over_short;
            $uncostedPosOrders += $rollup->uncosted_pos_orders;

            if ($shop->platform_type === PlatformType::Pos) {
                $posRevenue += $rollup->pos_revenue;
                $posCogs += $rollup->pos_cogs;
                $posNet += $rollup->pos_net;
            } else {
                // Marketplace (and Social) use the marketplace_actual_net columns.
                $marketplaceNet += $rollup->marketplace_actual_net;

                foreach (($rollup->fee_breakdown ?? []) as $category => $amount) {
                    $cat = (string) $category;
                    $feeBreakdown[$cat] = ($feeBreakdown[$cat] ?? 0) + (int) $amount;
                }
            }
        }

        // Operating expenses: full tenant-level direct query (see class docblock).
        $operatingExpenses = (int) Expense::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        // Combined net: Σ marketplace_net + Σ pos_net − operating expenses
        // + cash_over_short (per-order COGS is already folded into pos_net
        // and marketplace_actual_net — ADR 0020 / CONTEXT.md: Accounting Entry).
        $combinedNet = $canViewCost
            ? $marketplaceNet + $posNet - $operatingExpenses + $cashOverShort
            : null;

        return [
            'marketplace_net' => $marketplaceNet,
            'fee_breakdown' => $feeBreakdown,
            'pos_revenue' => $posRevenue,
            'pos_cogs' => $canViewCost ? $posCogs : null,
            'pos_net' => $canViewCost ? $posNet : null,
            'operating_expenses' => $operatingExpenses,
            'cash_over_short' => $cashOverShort,
            'combined_net' => $combinedNet,
            'uncosted_pos_orders' => $uncostedPosOrders,
            'can_view_cost' => $canViewCost,
        ];
    }
}
