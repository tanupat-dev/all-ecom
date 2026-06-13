<?php

namespace App\Actions\Accounting;

use App\Enums\PlatformType;
use App\Enums\ReconciliationStatus;
use App\Models\Order;
use InvalidArgumentException;

/**
 * Grades an Order's Reconciliation Status (CONTEXT.md: Reconciliation Status;
 * ADR 0007) by comparing its Actual Net to its Expected Net within the Shop's
 * Mismatch Threshold, and denormalizes the result onto the Order. Called at
 * both money edges — after Actual Net lands (UpsertAccountingCycle) and after
 * Expected Net is recomputed (RecomputeShopExpectedNet) — so a late accounting
 * cycle OR a Fee Profile change re-grades it; a previously `paid_ok` Order can
 * legitimately move to `paid_mismatch` when a later deduction posts (ADR 0007).
 *
 *   actual_net null                     → not_yet_paid (no accounting imported
 *                                         yet / still within the Platform hold)
 *   expected_net null                   → not_yet_paid (no baseline to check
 *                                         against — in practice Expected Net is
 *                                         set at Order import; we never grade a
 *                                         mismatch against a null estimate)
 *   |actual − expected| ≤ threshold     → paid_ok
 *   |actual − expected| >  threshold    → paid_mismatch
 *
 * The comparison is pure integer satang (ADR 0015) — actual, expected, and the
 * threshold are all satang; no float, no baht conversion. The threshold is the
 * Shop's `mismatch_threshold` (CONTEXT.md: Mismatch Threshold), default ฿1 =
 * 100 satang, which absorbs the half-up rounding of the fee estimate.
 *
 * Marketplace Orders only: a POS Order has no Reconciliation Status (its money
 * is collected in hand at the sale — no hold, no settlement, nothing to
 * reconcile), so this Action refuses one.
 */
class ComputeReconciliationStatus
{
    /** Default Mismatch Threshold when the Shop has none set: ฿1 = 100 satang. */
    private const DEFAULT_THRESHOLD_SATANG = 100;

    public function handle(Order $order): void
    {
        if ($order->platform_type === PlatformType::Pos) {
            throw new InvalidArgumentException('A POS Order has no Reconciliation Status — its money is collected in hand at the sale (CONTEXT.md).');
        }

        // No Actual Net yet: the accounting Excel has not been imported, or the
        // Order is still within the Platform hold period. Nothing to compare.
        if ($order->actual_net === null) {
            $this->save($order, ReconciliationStatus::NotYetPaid);

            return;
        }

        // No Expected Net baseline: cannot reconcile against nothing. In
        // practice Expected Net is set at Order import; this guards an Order
        // whose estimate has not been computed so we never grade a mismatch
        // against a null baseline.
        if ($order->expected_net === null) {
            $this->save($order, ReconciliationStatus::NotYetPaid);

            return;
        }

        $thresholdSatang = $order->shop->settings?->mismatch_threshold->satang
            ?? self::DEFAULT_THRESHOLD_SATANG;

        $differenceSatang = abs($order->actual_net->satang - $order->expected_net->satang);

        $this->save(
            $order,
            $differenceSatang <= $thresholdSatang
                ? ReconciliationStatus::PaidOk
                : ReconciliationStatus::PaidMismatch,
        );
    }

    private function save(Order $order, ReconciliationStatus $status): void
    {
        $order->reconciliation_status = $status;
        $order->save();
    }
}
