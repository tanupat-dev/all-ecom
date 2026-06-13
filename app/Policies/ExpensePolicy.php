<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 *
 * viewAny / view: accounting.view — seeing the Expenses list.
 * create / update / delete: accounting.manage — writing expenses.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('accounting.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->checkPermissionTo('accounting.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('accounting.manage');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->checkPermissionTo('accounting.manage');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->checkPermissionTo('accounting.manage');
    }
}
