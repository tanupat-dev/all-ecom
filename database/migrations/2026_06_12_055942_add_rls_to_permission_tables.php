<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Roles and their assignments are per-Tenant (ADR 0012, spatie teams =
     * tenant_id) — they get the same RLS backstop as every tenant table.
     * Safe to combine with spatie's permission cache ONLY because the cache
     * store is per-request ('array') and TenantContext flushes the
     * registrar on every tenant switch — a shared cache warmed under RLS
     * would poison other tenants.
     */
    public function up(): void
    {
        Rls::enable('roles');
        Rls::enable('model_has_roles');
        Rls::enable('model_has_permissions');
    }

    public function down(): void
    {
        Rls::disable('model_has_permissions');
        Rls::disable('model_has_roles');
        Rls::disable('roles');
    }
};
