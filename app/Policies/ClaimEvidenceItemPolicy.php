<?php

namespace App\Policies;

use App\Models\ClaimEvidenceItem;
use App\Models\User;

/**
 * Evidence items are part of the Claim work — gated on the same named
 * Permissions as the parent Claim (ADR 0012): read on `claim.view`,
 * all mutations on `claim.manage`.
 */
class ClaimEvidenceItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('claim.view');
    }

    public function view(User $user, ClaimEvidenceItem $item): bool
    {
        return $user->checkPermissionTo('claim.view');
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo('claim.manage');
    }

    public function update(User $user, ClaimEvidenceItem $item): bool
    {
        return $user->checkPermissionTo('claim.manage');
    }

    public function delete(User $user, ClaimEvidenceItem $item): bool
    {
        return $user->checkPermissionTo('claim.manage');
    }
}
