<?php

namespace App\Actions\Accounting;

use App\Actions\Pos\ComputePosOrderNet;
use App\Actions\Pos\ShiftCashOverShort;
use App\Enums\PlatformType;
use App\Models\AccountingEntryLine;
use App\Models\DailyPnlRollup;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Shift;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Recomputes ONE (shop, date) Daily P&L rollup row entirely from raw, then
 * upserts it (Issue #71). Idempotent: recomputing yields the same row, never
 * accumulates — so a re-import (UpsertAccountingCycle replacing a cycle's
 * lines) can never double the rollup (ADR 0007). Integer satang throughout
 * (ADR 0015). Reports read the rollup; they never SUM the raw ledger at
 * request time (ROADMAP Phase-1 scaling rule) — this Action is the only
 * place the raw scan happens, and only for a single (shop, date).
 *
 * DATE BUCKET — P&L is recognised at the sale, not at settlement. The `date`
 * argument is an Asia/Bangkok calendar date:
 *   - marketplace + POS Orders bucket by `created_date` (the sale moment,
 *     stored UTC → the Bangkok day spans a half-open UTC range below);
 *   - Expenses by `expense.date` (already a plain Bangkok calendar date);
 *   - Cash Over/Short by the Shift's `closed_at` (UTC → Bangkok day).
 * Settlement Date is deliberately NOT used (that is when money arrives, not
 * when the sale is earned — ADR 0007).
 *
 * The tenant context must already be set (the caller — the queued job via
 * RestoreTenantContext, or the artisan rebuild looping tenants — owns that),
 * since every read here is RLS-protected.
 */
class RecomputeDailyPnl
{
    /** The business timezone the P&L day is reckoned in (ROADMAP Phase 0). */
    private const TIMEZONE = 'Asia/Bangkok';

    public function __construct(
        private readonly ComputePosOrderNet $computePosOrderNet,
        private readonly ShiftCashOverShort $shiftCashOverShort,
    ) {}

    public function handle(int $shopId, CarbonInterface $date): void
    {
        $dateString = $date->toDateString();
        [$start, $end] = self::dayBoundsUtc($dateString);

        [$marketplaceActualNet, $feeBreakdown] = $this->marketplace($shopId, $start, $end);
        [$posRevenue, $posCogs, $posNet, $uncostedPosOrders] = $this->pos($shopId, $start, $end);

        DailyPnlRollup::query()->updateOrCreate(
            ['shop_id' => $shopId, 'date' => $dateString],
            [
                'marketplace_actual_net' => $marketplaceActualNet,
                'fee_breakdown' => $feeBreakdown,
                'pos_revenue' => $posRevenue,
                'pos_cogs' => $posCogs,
                'pos_net' => $posNet,
                'uncosted_pos_orders' => $uncostedPosOrders,
                'expense_total' => $this->expenseTotal($shopId, $dateString),
                'cash_over_short' => $this->cashOverShort($shopId, $start, $end),
            ],
        );
    }

    /**
     * The Asia/Bangkok calendar date of a UTC sale/close moment — the single
     * source of truth for the bucket rule, shared with the observers so the
     * dirty-marking key and the recompute query can never disagree.
     */
    public static function bucketDate(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * The half-open UTC range [start, end) covering one Asia/Bangkok day —
     * sargable on the UTC `created_date` / `closed_at` timestamp columns.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function dayBoundsUtc(string $dateString): array
    {
        $start = CarbonImmutable::parse($dateString, self::TIMEZONE)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /**
     * Marketplace side from raw: Σ of the day's marketplace Orders' accounting
     * lines (across ALL cycles — Actual Net is cycle-aggregate, ADR 0007),
     * split per category. Σ(breakdown) == marketplace_actual_net by build.
     *
     * @return array{0: int, 1: array<string, int>}
     */
    private function marketplace(int $shopId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $perCategory = AccountingEntryLine::query()
            ->join('orders', 'orders.id', '=', 'accounting_entry_lines.order_id')
            ->where('orders.shop_id', $shopId)
            ->where('orders.platform_type', PlatformType::Marketplace->value)
            ->where('orders.created_date', '>=', $start)
            ->where('orders.created_date', '<', $end)
            ->groupBy('accounting_entry_lines.category')
            ->selectRaw('accounting_entry_lines.category as category, SUM(accounting_entry_lines.amount) as total')
            ->pluck('total', 'category');

        $feeBreakdown = [];
        $actualNet = 0;

        foreach ($perCategory as $category => $total) {
            $satang = is_numeric($total) ? (int) $total : 0;
            $feeBreakdown[(string) $category] = $satang;
            $actualNet += $satang;
        }

        return [$actualNet, $feeBreakdown];
    }

    /**
     * POS side from raw. pos_net is the canonical Σ ComputePosOrderNet (the
     * single source for the POS net, #70). pos_revenue is the Payment total
     * net of cash change handed back (mirrors ComputePosOrderNet::revenue),
     * and pos_cogs is DERIVED as revenue − net so the three columns are always
     * internally consistent (revenue − cogs == net) and pos_cogs equals the
     * canonical Σ(costAt × qty) without re-deriving the bundle/cost logic.
     *
     * An uncosted Order (a Variant with no Cost Price at the sale date) cannot
     * be costed — ComputePosOrderNet fail-louds for the seller-facing on-demand
     * query (margin is never assumed zero, #70), but the daily rollup is a
     * reporting aggregate triggered by every POS sale: it must not crash the
     * checkout that fired it nor lose the whole shop-day to one uncosted order.
     * So we EXCLUDE the uncosted Order from all three POS totals (keeping
     * revenue − cogs == net exact for the orders we can cost) and COUNT it, so
     * the combined P&L (#72) can flag the day as incomplete rather than silently
     * understating COGS.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function pos(int $shopId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $revenue = 0;
        $net = 0;
        $uncosted = 0;

        Order::query()
            ->where('shop_id', $shopId)
            ->where('platform_type', PlatformType::Pos)
            ->where('created_date', '>=', $start)
            ->where('created_date', '<', $end)
            ->with('payments')
            ->chunkById(200, function ($orders) use (&$revenue, &$net, &$uncosted): void {
                foreach ($orders as $order) {
                    try {
                        $orderNet = $this->computePosOrderNet->handle($order)->satang;
                    } catch (\LogicException) {
                        $uncosted++;

                        continue;
                    }

                    $revenue += $this->posRevenue($order)->satang;
                    $net += $orderNet;
                }
            });

        return [$revenue, $revenue - $net, $net, $uncosted];
    }

    /**
     * Payment total net of the cash change handed back (= the Order total) —
     * mirrors ComputePosOrderNet::revenue (#70): summing the gross tenders
     * would overstate revenue by the change, which is not income.
     */
    private function posRevenue(Order $order): Money
    {
        $tendered = Money::fromSatang(0);

        foreach ($order->payments as $payment) {
            $tendered = $tendered->add($payment->amount ?? Money::fromSatang(0));
        }

        $change = $tendered->subtract($order->total ?? Money::fromSatang(0));

        if ($change->isNegative()) {
            $change = Money::fromSatang(0);
        }

        return $tendered->subtract($change);
    }

    /**
     * Operating Expenses on the day attributable to this shop. An Expense
     * carries no shop_id (CONTEXT.md: Expense) — its only shop link is
     * `ref_order_id`, so only per-order-attributable expenses land in this
     * per-shop rollup. Non-attributable operating expenses are tenant-level
     * (CONTEXT.md: "period-level monthly P&L") and are NOT in the per-shop
     * daily rollup — see the orchestrator note in the Issue #71 hand-back.
     */
    private function expenseTotal(int $shopId, string $dateString): int
    {
        return (int) Expense::query()
            ->where('date', $dateString)
            ->whereHas('refOrder', fn ($query) => $query->where('shop_id', $shopId))
            ->sum('amount');
    }

    /**
     * Signed Cash Over/Short for the shifts of this shop closed on the day.
     * A Shift links to a shop via its Register (Shift → Register → Shop).
     */
    private function cashOverShort(int $shopId, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $total = 0;

        Shift::query()
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $end)
            ->whereHas('register', fn ($query) => $query->where('shop_id', $shopId))
            ->each(function (Shift $shift) use (&$total): void {
                $total += $this->shiftCashOverShort->handle($shift)->satang;
            });

        return $total;
    }
}
