<?php

namespace App\Policies;

use App\Models\ListingVariant;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 */
class ListingVariantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('listing.view');
    }

    public function view(User $user, ListingVariant $mapping): bool
    {
        return $user->checkPermissionTo('listing.view');
    }

    public function update(User $user, ListingVariant $mapping): bool
    {
        return $user->checkPermissionTo('listing.manage');
    }
}
