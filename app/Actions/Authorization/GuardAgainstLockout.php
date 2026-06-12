<?php

namespace App\Actions\Authorization;

use App\Models\User;
use LogicException;

/**
 * The lock-out safeguard of ADR 0012: after any role/user mutation, at
 * least one User of the Tenant must still hold BOTH user.manage and
 * role.manage. Call inside the mutating transaction — throwing rolls the
 * change back.
 */
class GuardAgainstLockout
{
    public static function check(?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        $someoneCanManage = User::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->contains(fn (User $user): bool => $user->hasPermissionTo('user.manage')
                && $user->hasPermissionTo('role.manage'));

        if (! $someoneCanManage) {
            throw new LogicException('Refused: this change would leave the Tenant with no user able to manage users and roles (ADR 0012 lock-out safeguard).');
        }
    }
}
