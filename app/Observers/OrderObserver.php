<?php

namespace App\Observers;

use App\Jobs\TuneShopHoldPeriodJob;
use App\Models\Order;

/**
 * Re-tunes a Shop's Hold Period whenever a Settlement Date lands on one of its
 * Orders (Issue #68). Fires on EVERY write path that sets `settlement_date` —
 * the accounting importers' fill-only save (MarketplaceAccountingImporter ~L156)
 * and any future writer — without editing those importers.
 *
 * Guarded to the settlement-landing transition only: dispatch when
 * `settlement_date` was just changed AND is now non-null. A Settlement Date is
 * fill-only (never clobbered back to null), so this is the one transition that
 * adds a fresh sample to the median. The deduped job collapses a 500-row
 * import to one tune per Shop; TuneShopHoldPeriod no-ops for non-marketplace
 * Shops and below MIN_SAMPLE, so a stray save can never mis-tune.
 */
class OrderObserver
{
    public function saved(Order $order): void
    {
        if (! $order->wasChanged('settlement_date') || $order->settlement_date === null) {
            return;
        }

        if ($order->tenant_id === null) {
            return;
        }

        TuneShopHoldPeriodJob::dispatch($order->tenant_id, $order->shop_id);
    }
}
