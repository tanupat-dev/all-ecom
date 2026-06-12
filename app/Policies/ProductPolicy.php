<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('product.view');
    }

    public function view(User $user, Product $location): bool
    {
        return $user->checkPermissionTo('product.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('product.edit');
    }

    public function update(User $user, Product $location): bool
    {
        return $user->checkPermissionTo('product.edit');
    }

    public function delete(User $user, Product $location): bool
    {
        return $user->checkPermissionTo('product.edit');
    }
}
