<?php

namespace App\Actions\Claims;

use App\Models\Claim;
use App\Models\ClaimEvidenceItem;

/**
 * Seeds the four default proof items onto a Claim immediately after it is
 * created (CONTEXT.md: Claim, Evidence Checklist; Issue #82). All items start
 * unchecked (`checked = false`) and are flagged as system-supplied
 * (`is_default = true`) so the seller can distinguish them from custom items
 * they add later.
 *
 * Called by CreateClaim — do not call in isolation unless re-seeding is
 * explicitly intended (e.g. a future "reset evidence" feature).
 */
class SeedDefaultEvidence
{
    /**
     * The four canonical proof items (CONTEXT.md: Claim, Evidence Checklist).
     * Order is intentional: video evidence first, then physical/weight, then photo.
     */
    private const ITEMS = [
        'Outgoing packing/shipping video',
        'Incoming unboxing video',
        'Weight on scale (before/after)',
        'Photos of received goods',
    ];

    public function handle(Claim $claim): void
    {
        foreach (self::ITEMS as $label) {
            ClaimEvidenceItem::query()->create([
                'claim_id' => $claim->id,
                'label' => $label,
                'checked' => false,
                'is_default' => true,
            ]);
        }
    }
}
