<?php

namespace App\Actions\Listings;

use App\Enums\ListingStatus;
use App\Models\ListingVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Transitions a ListingVariant's listing_status from `draft` to `listed`
 * (CONTEXT.md: Listing Status; ADR 0019).
 *
 * Called when the seller has uploaded the filled Channel Upload Template to
 * the Platform and wants to confirm the upload in all-ecom so Coverage moves
 * from intent (draft) to reality (listed).
 *
 * Idempotent: confirming an already-listed row is a no-op.
 * Gated on `listing.manage` (ADR 0012 / ListingVariantPolicy::update).
 */
class ConfirmListingUpload
{
    /**
     * @throws AuthorizationException if the current user lacks listing.manage
     *                                or the row belongs to another tenant
     */
    public function handle(ListingVariant $listingVariant): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->can('update', $listingVariant)) {
            throw new AuthorizationException('Confirming a listing upload requires the listing.manage permission.');
        }

        // Defense-in-depth: even if a caller bypasses the global scope, the
        // row must belong to the authenticated user's tenant (ADR 0011).
        if ($listingVariant->tenant_id !== $user->tenant_id) {
            throw new AuthorizationException('Cannot confirm a listing upload that belongs to another tenant.');
        }

        // Idempotent — already listed is the desired terminal state.
        if ($listingVariant->listing_status === ListingStatus::Listed) {
            return;
        }

        $listingVariant->update(['listing_status' => ListingStatus::Listed]);
    }
}
