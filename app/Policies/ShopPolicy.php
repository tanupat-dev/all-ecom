<?php

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 */
class ShopPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('shop.view');
    }

    public function view(User $user, Shop $location): bool
    {
        return $user->checkPermissionTo('shop.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('shop.edit');
    }

    public function update(User $user, Shop $location): bool
    {
        return $user->checkPermissionTo('shop.edit');
    }

    public function delete(User $user, Shop $location): bool
    {
        return $user->checkPermissionTo('shop.edit');
    }

    /**
     * Downloading the Shop's stock-update Excel exposes stock numbers —
     * the same gate as reading them.
     */
    public function exportStock(User $user, Shop $shop): bool
    {
        return $user->checkPermissionTo('stock.view');
    }
}
