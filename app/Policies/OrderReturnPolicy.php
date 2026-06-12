<?php

namespace App\Policies;

use App\Models\OrderReturn;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 */
class OrderReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('return.view');
    }

    public function view(User $user, OrderReturn $return): bool
    {
        return $user->checkPermissionTo('return.view');
    }

    /**
     * Inbound Scan and manual closure act on the Return.
     */
    public function update(User $user, OrderReturn $return): bool
    {
        return $user->checkPermissionTo('return.manage');
    }
}
