<?php

namespace App\Actions\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Role;

/**
 * Deletes a Tenant Role (ADR 0012): a Role in use is first stripped from
 * its users (the UI warns); the lock-out safeguard rolls everything back
 * if the strip would leave nobody managing users/roles.
 *
 * @return int how many users the role was stripped from
 */
class DeleteRole
{
    public function handle(Role $role, ?int $tenantId): int
    {
        return DB::transaction(function () use ($role, $tenantId): int {
            $holders = User::query()
                ->where('tenant_id', $tenantId)
                ->role($role)
                ->get();

            foreach ($holders as $holder) {
                $holder->removeRole($role);
            }

            $role->delete();

            GuardAgainstLockout::check($tenantId);

            return $holders->count();
        });
    }
}
