<?php

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 */
class ListingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('listing.view');
    }

    public function view(User $user, Listing $listing): bool
    {
        return $user->checkPermissionTo('listing.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('listing.manage');
    }

    public function update(User $user, Listing $listing): bool
    {
        return $user->checkPermissionTo('listing.manage');
    }

    public function delete(User $user, Listing $listing): bool
    {
        return $user->checkPermissionTo('listing.manage');
    }
}
