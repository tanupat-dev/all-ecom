<?php

namespace App\Actions\Claims;

use App\Enums\ClaimType;
use App\Enums\ReturnReasonFault;
use App\Models\Claim;
use App\Models\OrderReturn;

/**
 * Conditionally creates an `eligible` `return_fee` Claim for a seller-fault
 * Return (CONTEXT.md: Claim, Return Reason; Issue #80). The auto-flag is a
 * *prompt to verify* — not a confirmed finding of fault (buyers sometimes
 * mis-pick a seller-fault code). buyer_fault and null reason_fault → no Claim.
 *
 * Idempotent: guards on (tenant, ref_return_id, claim_type=return_fee) so
 * re-importing the same Return never creates a second Claim (ADR 0005).
 */
class FlagReturnFeeClaim
{
    public function __construct(private readonly CreateClaim $createClaim) {}

    /**
     * Returns the existing or newly-created Claim, or null if not applicable.
     */
    public function handle(OrderReturn $return): ?Claim
    {
        if ($return->reason_fault !== ReturnReasonFault::SellerFault) {
            return null;
        }

        // Idempotency guard — BelongsToTenant scope ensures the check is
        // tenant-scoped; a re-import of the same Return exits early here.
        $existing = Claim::query()
            ->where('ref_return_id', $return->id)
            ->where('claim_type', ClaimType::ReturnFee)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $order = $return->order()->firstOrFail();

        return $this->createClaim->handle($order, ClaimType::ReturnFee, $return);
    }
}
