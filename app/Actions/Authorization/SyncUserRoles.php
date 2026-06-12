<?php

namespace App\Actions\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Role;

/**
 * Replaces a User's Roles, enforcing the lock-out safeguard (ADR 0012):
 * no change may leave the Tenant with no user able to manage users and
 * roles.
 */
class SyncUserRoles
{
    /**
     * @param  list<Role>  $roles
     */
    public function handle(User $user, array $roles): void
    {
        DB::transaction(function () use ($user, $roles): void {
            $user->syncRoles($roles);

            GuardAgainstLockout::check($user->tenant_id);
        });
    }
}
