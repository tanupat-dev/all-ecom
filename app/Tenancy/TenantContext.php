<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the current Tenant and mirrors it into the Postgres session variable
 * `app.current_tenant` that every RLS policy reads (ADR 0016/0018). Re-applied
 * on every new DB connection via the ConnectionEstablished listener registered
 * in AppServiceProvider, so a reconnect can never drop the tenant context.
 */
class TenantContext
{
    private ?Tenant $current = null;

    public function set(Tenant $tenant): void
    {
        $this->current = $tenant;
        $this->applyToConnection();
        $this->applyToPermissions();
    }

    public function forget(): void
    {
        $this->current = null;
        $this->applyToConnection();
        $this->applyToPermissions();
    }

    /**
     * Roles are per-Tenant (spatie teams = tenant_id, ADR 0012). The
     * registrar's cache is flushed on every switch — it is per-request
     * ('array' store) precisely so a cache warmed under one tenant's RLS
     * view can never leak into another (see the RLS permission migration).
     */
    private function applyToPermissions(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->current?->id);
        $registrar->forgetCachedPermissions();
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    /**
     * An empty string resolves to NULL in the policy's NULLIF(...) — RLS then
     * matches no rows: the DB layer fails closed when no tenant is set.
     */
    public function applyToConnection(?string $connection = null): void
    {
        DB::connection($connection)->statement(
            "select set_config('app.current_tenant', ?, false)",
            [$this->current?->id !== null ? (string) $this->current->id : ''],
        );
    }
}
