<?php

namespace App\Actions\Claims;

use App\Models\ClaimEvidenceItem;

/**
 * Checks or unchecks a Claim evidence item (CONTEXT.md: Claim, Evidence
 * Checklist; Issue #82). `checked` is a mutable bool — a working checklist,
 * not an append-only ledger — so a direct update is correct here. Works for
 * both default and custom items.
 *
 * Gated on `claim.manage` (Policy: ClaimEvidenceItemPolicy::update).
 */
class SetClaimEvidenceChecked
{
    public function handle(ClaimEvidenceItem $item, bool $checked): ClaimEvidenceItem
    {
        $item->update(['checked' => $checked]);

        return $item;
    }
}
