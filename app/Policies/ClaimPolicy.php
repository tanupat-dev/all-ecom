<?php

namespace App\Policies;

use App\Models\Claim;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name. Claims are
 * back-office work: read on claim.view, all mutation on claim.manage.
 */
class ClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('claim.view');
    }

    public function view(User $user, Claim $claim): bool
    {
        return $user->checkPermissionTo('claim.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('claim.manage');
    }

    public function update(User $user, Claim $claim): bool
    {
        return $user->checkPermissionTo('claim.manage');
    }

    public function delete(User $user, Claim $claim): bool
    {
        return $user->checkPermissionTo('claim.manage');
    }
}
