<?php

namespace App\Policies;

use App\Models\ImportJob;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name.
 *
 * viewAny / view: listing.manage — ImportJobs surface template fill results,
 * so the same permission that starts a fill controls visibility of the list.
 * downloadFilledResult: listing.manage — same guard as the fill trigger.
 */
class ImportJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('listing.manage');
    }

    public function view(User $user, ImportJob $importJob): bool
    {
        return $user->checkPermissionTo('listing.manage');
    }

    public function downloadFilledResult(User $user, ImportJob $importJob): bool
    {
        return $user->checkPermissionTo('listing.manage');
    }
}
