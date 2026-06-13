<?php

namespace App\Policies;

use App\Models\Promotion;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 *
 * viewAny / view: promotion.view. create / update / delete: promotion.manage.
 */
class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('promotion.view');
    }

    public function view(User $user, Promotion $promotion): bool
    {
        return $user->checkPermissionTo('promotion.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('promotion.manage');
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $user->checkPermissionTo('promotion.manage');
    }

    public function delete(User $user, Promotion $promotion): bool
    {
        return $user->checkPermissionTo('promotion.manage');
    }
}
