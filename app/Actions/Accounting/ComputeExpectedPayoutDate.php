<?php

namespace App\Actions\Accounting;

use App\Enums\PlatformType;
use App\Models\Order;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Computes and denormalizes the Expected Payout Date onto an Order
 * (CONTEXT.md: Expected Payout Date; Issue #67).
 *
 *   expected_payout_date = anchor_date + hold_period (days)
 *
 * where `anchor_date = $order->{payout_anchor}` (the milestone field named
 * by the Shop's `payout_anchor` setting — `completed_date` for Shopee,
 * `delivered_date` for TikTok/Lazada; ADR 0004). If the anchor milestone
 * is null (goods not yet delivered/completed), expected_payout_date is set
 * to null — no date yet.
 *
 * A misconfigured anchor silently shifts the date — this is documented
 * behaviour (ADR 0004): the per-Shop `payout_anchor` must match the
 * Platform's payout clock. A test changing the setting proves the date moves.
 *
 * Marketplace Orders only: a POS Order has no payout (money collected in
 * hand at the sale), so this Action refuses one.
 */
class ComputeExpectedPayoutDate
{
    public function handle(Order $order): void
    {
        if ($order->platform_type === PlatformType::Pos) {
            throw new InvalidArgumentException(
                'A POS Order has no Expected Payout Date — its money is collected in hand with no platform hold (CONTEXT.md).'
            );
        }

        $settings = $order->shop->settings;

        if ($settings === null) {
            // Non-marketplace shop (social) or unconfigured: leave null.
            $order->expected_payout_date = null;
            $order->save();

            return;
        }

        $anchor = $settings->payout_anchor;    // e.g. 'completed_date'
        $holdPeriod = $settings->hold_period;  // int days

        /** @var Carbon|null $anchorDate */
        $anchorDate = $order->{$anchor};       // nullable Carbon from the flat milestones

        if ($anchorDate === null) {
            // Anchor milestone not yet reached — no payout date yet.
            $order->expected_payout_date = null;
        } else {
            $order->expected_payout_date = $anchorDate->copy()->addDays($holdPeriod);
        }

        $order->save();
    }
}
