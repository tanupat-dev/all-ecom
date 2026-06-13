<?php

namespace App\Actions\Pos;

use App\Enums\PlatformType;
use App\Models\Order;
use App\Support\Money;
use DateTimeInterface;
use InvalidArgumentException;
use LogicException;

/**
 * The POS direct P&L net for one Order (CONTEXT.md: Accounting Entry — POS
 * paragraph; ADR 0009/0015): net = revenue − COGS, with NO platform-fee leg.
 * A POS Order has no Accounting Entry — its money is collected in hand — so
 * its contribution to the combined P&L is computed directly here. The
 * marketplace side instead reads Actual Net off the Accounting Entry.
 *
 * revenue = the Payment total NET OF CHANGE. POS Payment Lines store the
 * gross tender (cash change is handed back, never kept), so the amount the
 * business actually keeps = Σ payments − change, change = max(0, Σ payments
 * − total). This mirrors CloseShift::cashSalesNet exactly and resolves to
 * the Order total; summing the gross tenders would OVERSTATE revenue by the
 * cash change handed back, which is not income.
 *
 * COGS = Σ over the lines of Variant::costAt(sale date) × qty (CONVENTIONS
 * rule 9 — profit uses the cost at the sale date, never the current cost; a
 * Bundle expands to component cost inside costAt, ADR 0014). The sale date
 * is the Order's created_date — POS is instant (paid = completed = created).
 *
 * A POS Return (ADR 0009) is a linked negative-line Order: its payments and
 * its line qty are negative, so revenue and COGS are both negative and the
 * net is the (negative) margin reversed — the same arithmetic, no branch.
 *
 * Fail-loud (ADR 0005): a line whose Variant has NO Cost Price at the sale
 * date has an UNKNOWN margin. The P&L must not silently pretend cost = 0, so
 * this throws naming the Variant — the seller must set a Cost Price.
 *
 * Visibility of the resulting profit is gated cost.view (ADR 0012) at the
 * consumer (the daily rollup / report, #71/#72); this Action is the
 * computation only and performs no display gating.
 */
class ComputePosOrderNet
{
    public function handle(Order $order): Money
    {
        if ($order->platform_type !== PlatformType::Pos) {
            throw new InvalidArgumentException('ComputePosOrderNet handles only POS Orders — marketplace P&L comes from the Accounting Entry (Actual Net).');
        }

        $saleDate = $order->created_date
            ?? throw new LogicException('A POS Order must have a created_date (the sale moment) to compute its P&L.');

        return $this->revenue($order)->subtract($this->cogs($order, $saleDate));
    }

    /** Payment total net of the cash change handed back (= Order total). */
    private function revenue(Order $order): Money
    {
        $tendered = Money::fromSatang(0);

        foreach ($order->payments()->get() as $payment) {
            $tendered = $tendered->add($payment->amount ?? Money::fromSatang(0));
        }

        $change = $tendered->subtract($order->total ?? Money::fromSatang(0));

        if ($change->isNegative()) {
            $change = Money::fromSatang(0);
        }

        return $tendered->subtract($change);
    }

    private function cogs(Order $order, DateTimeInterface $saleDate): Money
    {
        $cogs = Money::fromSatang(0);

        foreach ($order->lines()->with('variant')->get() as $line) {
            $variant = $line->variant
                ?? throw new LogicException("Order Line [{$line->id}] has no Variant — cannot compute COGS.");

            $cost = $variant->costAt($saleDate)
                ?? throw new LogicException("Variant [{$variant->master_sku}] has no Cost Price at the sale date — set a Cost Price so the POS P&L margin is known (it is never assumed zero).");

            $cogs = $cogs->add($cost->multiply($line->qty));
        }

        return $cogs;
    }
}
