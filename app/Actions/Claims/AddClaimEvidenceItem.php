<?php

namespace App\Actions\Claims;

use App\Models\Claim;
use App\Models\ClaimEvidenceItem;

/**
 * Adds a seller-defined custom evidence item to an existing Claim
 * (CONTEXT.md: Claim, Evidence Checklist; Issue #82). Custom items start
 * unchecked and are flagged `is_default = false` to distinguish them from the
 * four system-seeded defaults.
 *
 * Gated on `claim.manage` (Policy: ClaimEvidenceItemPolicy::create).
 */
class AddClaimEvidenceItem
{
    public function handle(Claim $claim, string $label): ClaimEvidenceItem
    {
        return ClaimEvidenceItem::query()->create([
            'claim_id' => $claim->id,
            'label' => $label,
            'checked' => false,
            'is_default' => false,
        ]);
    }
}
