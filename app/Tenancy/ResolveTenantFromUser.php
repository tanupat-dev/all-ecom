<?php

namespace App\Tenancy;

use App\Models\User;
use Illuminate\Auth\Events\Authenticated;

/**
 * The tenant resolve at login (ROADMAP Phase 2): every authenticated
 * request inherits its User's Tenant, so the global scope and RLS session
 * var are set before any domain query runs. A guest or tenant-less user
 * leaves no context — RLS then fails closed (ADR 0018).
 */
class ResolveTenantFromUser
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(Authenticated $event): void
    {
        $user = $event->user;

        if ($user instanceof User && $user->tenant !== null) {
            $this->context->set($user->tenant);
        }
    }
}
