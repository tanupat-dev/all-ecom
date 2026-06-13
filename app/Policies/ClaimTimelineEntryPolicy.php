<?php

namespace App\Policies;

use App\Models\ClaimTimelineEntry;
use App\Models\User;

/**
 * Every gate is a named Permission (ADR 0012) — never a role name. The Claim
 * Timeline shares the Claim's permissions: read on claim.view, appending on
 * claim.manage. The Timeline is append-only (Issue #83) — there is no update
 * or delete gate because there is no mutation path to gate.
 */
class ClaimTimelineEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('claim.view');
    }

    public function view(User $user, ClaimTimelineEntry $entry): bool
    {
        return $user->checkPermissionTo('claim.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('claim.manage');
    }
}
