<?php

namespace App\Actions\Claims;

use App\Enums\AccountingLineCategory;
use App\Enums\ClaimType;
use App\Models\Claim;
use App\Models\Order;

/**
 * Conditionally creates an `eligible` `shipping_overcharge` Claim when the
 * courier billed the seller above the catalogue-expected rate (CONTEXT.md:
 * Claim; ADR 0022; Issue #85). Like every auto-flag it is a *prompt to verify*
 * — never an automated recovery; a human reviews before filing.
 *
 * Compares |shipping_seller_paid| (the Accounting Entry line is signed
 * negative — the seller pays; ADR 0020) against ComputeExpectedShipping, and
 * flags only when the overcharge exceeds TOLERANCE (absorbing courier rounding
 * and small surcharges). A null expected fee (missing rate/weight/dims) means
 * "cannot assess" → no flag (fail-safe, ADR 0005).
 *
 * Idempotent: guards on (tenant, ref_order_id, claim_type=shipping_overcharge)
 * so re-importing the same Accounting cycle never creates a second Claim.
 *
 * Money is integer satang throughout (ADR 0015).
 */
class FlagShippingOverchargeClaim
{
    /** ฿5 — absorbs courier rounding + small fuel/remote surcharges (ADR 0022 §4). */
    private const TOLERANCE_SATANG = 500;

    public function __construct(
        private readonly ComputeExpectedShipping $computeExpectedShipping,
        private readonly CreateClaim $createClaim,
    ) {}

    /**
     * Returns the existing or newly-created Claim, or null if not applicable.
     */
    public function handle(Order $order): ?Claim
    {
        $expected = $this->computeExpectedShipping->handle($order);

        if ($expected === null) {
            return null;
        }

        // |Σ shipping_seller_paid| in satang — integer-column aggregate, never
        // through float (mirrors UpsertAccountingCycle). The lines are signed
        // negative (seller pays), so the absolute value is what was paid.
        $paidSatang = abs((int) $order->accountingEntryLines()
            ->where('category', AccountingLineCategory::ShippingSellerPaid)
            ->sum('amount'));

        if ($paidSatang - $expected->satang <= self::TOLERANCE_SATANG) {
            return null;
        }

        // Idempotency guard — BelongsToTenant scope keeps the check
        // tenant-scoped; a re-import of the same cycle exits early here.
        $existing = Claim::query()
            ->where('ref_order_id', $order->id)
            ->where('claim_type', ClaimType::ShippingOvercharge)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createClaim->handle($order, ClaimType::ShippingOvercharge);
    }
}
