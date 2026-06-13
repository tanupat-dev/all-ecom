<?php

namespace App\Actions\Accounting;

use App\Enums\PlatformType;
use App\Models\Order;
use App\Models\PlatformFeeProfile;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Computes an Order's Expected Net (CONTEXT.md: Expected Net; Issue #65) and
 * denormalizes it onto the Order — the forward-looking number the seller
 * expects to receive, set when the Order is imported and whenever the Shop's
 * Platform Fee Profile changes (via RecomputeShopExpectedNet). The read path
 * never recomputes fees at report time.
 *
 *   Expected Net = Effective Price total − Σ expected fees
 *
 * Effective Price total = Σ of the lines' `line_total` (the imported ราคาขาย;
 * CONTEXT.md: Effective Price). `order_lines.line_total` is NOT NULL at the
 * schema and already nets the line's Manual Discount, so it is exactly the
 * line's Effective Price — no unit_price * qty fallback is reachable. The sum
 * is an integer-column aggregate done at write time (mirroring how Actual Net
 * is summed in UpsertAccountingCycle), not a report-time recomputation. Each
 * fee = the Shop's profile rate applied to that total plus its flat per-order
 * fee:
 *
 *   feeSatang = round_half_up(effectivePriceTotal * rate_bps / 10000) + fixed_satang
 *
 * All integer satang, no float (ADR 0015). Rounding is half-up at whole
 * satang (the `+ 5000` before dividing by 10000), pinned by a test — fees
 * round in the seller's conservative direction (a fee is never under-counted
 * by the rounding step, so Expected Net is never optimistic by it).
 *
 * Marketplace Orders only: a POS Order has no platform fees (money in hand at
 * the sale), so this Action refuses one.
 */
class ComputeExpectedNet
{
    public function handle(Order $order): void
    {
        if ($order->platform_type === PlatformType::Pos) {
            throw new InvalidArgumentException('A POS Order has no Expected Net — its money is collected in hand with no platform fees (CONTEXT.md).');
        }

        // Integer-column aggregate (satang) — exact, no Money/float in the sum.
        $effectivePriceTotal = (int) $order->lines()->sum('line_total');

        $totalExpectedFees = 0;

        $profiles = PlatformFeeProfile::query()
            ->where('shop_id', $order->shop_id)
            ->get();

        foreach ($profiles as $profile) {
            // Half-up at whole satang: + 5000 before /10000. Both operands are
            // non-negative (a price total and a rate), so this rounds away
            // from zero deterministically; pinned by a test.
            $rateFee = intdiv($effectivePriceTotal * $profile->rate_bps + 5000, 10000);
            $totalExpectedFees += $rateFee + $profile->fixed_satang;
        }

        $order->expected_net = Money::fromSatang($effectivePriceTotal - $totalExpectedFees);
        $order->save();
    }
}
