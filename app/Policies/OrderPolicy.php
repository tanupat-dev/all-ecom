<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('order.view');
    }

    public function view(User $user, Order $location): bool
    {
        return $user->checkPermissionTo('order.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('order.import');
    }

    public function update(User $user, Order $location): bool
    {
        return $user->checkPermissionTo('order.import');
    }

    public function delete(User $user, Order $location): bool
    {
        return $user->checkPermissionTo('order.import');
    }
}
