<?php

namespace App\Actions\Accounting;

use App\Enums\PlatformType;
use App\Models\Order;
use App\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Auto-tunes a marketplace Shop's Hold Period from its own settled-Order
 * history (CONTEXT.md: Hold Period; Issue #68).
 *
 *   hold_period = median( payout_anchor_date → settlement_date ), in whole days
 *
 * over every Order of the Shop that has BOTH a Settlement Date AND its anchor
 * milestone (the column named by the Shop's `payout_anchor`). The anchor is
 * `completed_date` for Shopee, `delivered_date` for TikTok/Lazada (ADR 0004).
 *
 * WHY A MINIMUM SAMPLE (ADR 0005 — never guess): a Platform changes its
 * payout clock rarely, and a handful of early settlements is noise, not a
 * trend. Below MIN_SAMPLE settled Orders the manually-set `hold_period`
 * STANDS — we never overwrite a deliberate value with a guess. A Platform
 * that does not expose a Settlement Date at all (TikTok's export may omit it)
 * never accumulates settled Orders, so it never reaches MIN_SAMPLE and keeps
 * its manual value automatically — no special-casing needed.
 *
 * Whole-day counting: the day-delta is the number of calendar days between
 * the anchor date and the settlement date (both normalised to start-of-day),
 * so a sub-day time-of-day difference never under/over-counts the hold.
 */
class TuneShopHoldPeriod
{
    /**
     * Minimum number of settled Orders before the historical median is
     * trusted to override the manual `hold_period`. Below this the manual
     * value stands (ADR 0005 — never guess from too little signal).
     */
    public const MIN_SAMPLE = 5;

    public function handle(Shop $shop): void
    {
        // Only marketplace Shops have a payout / Hold Period at all.
        if ($shop->platform_type !== PlatformType::Marketplace) {
            return;
        }

        $settings = $shop->settings;

        if ($settings === null) {
            return;
        }

        $anchor = $settings->payout_anchor; // e.g. 'completed_date'

        /** @var Collection<int, Order> $settled */
        $settled = Order::query()
            ->where('shop_id', $shop->id)
            ->whereNotNull('settlement_date')
            ->whereNotNull($anchor)
            ->get();

        // Too few settled Orders (incl. zero for a Platform that never exposes
        // a Settlement Date) → leave the manual hold_period untouched.
        if ($settled->count() < self::MIN_SAMPLE) {
            return;
        }

        /** @var Collection<int, int> $days */
        $days = $settled
            ->map(function (Order $order) use ($anchor): int {
                /** @var Carbon $anchorDate */
                $anchorDate = $order->{$anchor};
                /** @var Carbon $settlementDate */
                $settlementDate = $order->settlement_date;

                return (int) $anchorDate->copy()->startOfDay()
                    ->diffInDays($settlementDate->copy()->startOfDay(), absolute: true);
            })
            ->sort()
            ->values();

        $settings->update(['hold_period' => $this->median($days)]);
    }

    /**
     * Median of an ascending list of whole-day deltas. An odd count takes the
     * single middle value; an EVEN count averages the two middle values and
     * rounds half up to a whole day (round(6.5) === 7) — pinned by test.
     *
     * @param  Collection<int, int>  $sorted
     */
    private function median(Collection $sorted): int
    {
        $count = $sorted->count();
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (int) $sorted[$mid];
        }

        return (int) round(((int) $sorted[$mid - 1] + (int) $sorted[$mid]) / 2);
    }
}
