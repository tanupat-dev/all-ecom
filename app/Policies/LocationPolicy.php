<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('location.view');
    }

    public function view(User $user, Location $location): bool
    {
        return $user->checkPermissionTo('location.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('location.edit');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->checkPermissionTo('location.edit');
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->checkPermissionTo('location.edit');
    }
}
