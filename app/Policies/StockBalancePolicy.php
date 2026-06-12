<?php

namespace App\Policies;

use App\Models\StockBalance;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 * Balances are read-only; Buffer edits and transfers are stock.adjust.
 */
class StockBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('stock.view');
    }

    public function view(User $user, StockBalance $stockBalance): bool
    {
        return $user->checkPermissionTo('stock.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockBalance $stockBalance): bool
    {
        return $user->checkPermissionTo('stock.adjust');
    }

    public function delete(User $user, StockBalance $stockBalance): bool
    {
        return false;
    }
}
