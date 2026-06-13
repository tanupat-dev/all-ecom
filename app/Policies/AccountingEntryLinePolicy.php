<?php

namespace App\Policies;

use App\Models\AccountingEntryLine;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 *
 * viewAny / view: accounting.view — seeing the Order's Accounting Entry lines.
 * manage: accounting.manage — importing/replacing an Order's accounting cycle.
 */
class AccountingEntryLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('accounting.view');
    }

    public function view(User $user, AccountingEntryLine $accountingEntryLine): bool
    {
        return $user->checkPermissionTo('accounting.view');
    }

    public function manage(User $user): bool
    {
        return $user->checkPermissionTo('accounting.manage');
    }
}
