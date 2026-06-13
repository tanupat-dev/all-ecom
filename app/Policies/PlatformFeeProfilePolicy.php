<?php

namespace App\Policies;

use App\Models\PlatformFeeProfile;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 *
 * viewAny / view: accounting.view — reading the Shop's expected fee rates.
 * create / update / delete: accounting.manage — changing what the system
 * predicts the Platform will deduct. (Permissions from Issue #61.)
 */
class PlatformFeeProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('accounting.view');
    }

    public function view(User $user, PlatformFeeProfile $platformFeeProfile): bool
    {
        return $user->checkPermissionTo('accounting.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('accounting.manage');
    }

    public function update(User $user, PlatformFeeProfile $platformFeeProfile): bool
    {
        return $user->checkPermissionTo('accounting.manage');
    }

    public function delete(User $user, PlatformFeeProfile $platformFeeProfile): bool
    {
        return $user->checkPermissionTo('accounting.manage');
    }
}
