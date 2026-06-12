<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * The shared RLS migration helper (ADR 0016): every domain-table migration
 * calls Rls::enable() so no table ships without ENABLE + FORCE + the
 * tenant_isolation policy. Idempotent — safe to re-run.
 */
class Rls
{
    private const PREDICATE = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::bigint";

    public static function enable(string $table): void
    {
        DB::statement("alter table {$table} enable row level security");
        // FORCE applies the policy to the table owner too (ADR 0016) — without
        // it RLS silently does nothing whenever the app connects as the owner.
        DB::statement("alter table {$table} force row level security");
        DB::statement("drop policy if exists tenant_isolation on {$table}");
        DB::statement(sprintf(
            'create policy tenant_isolation on %s as permissive for all using (%s) with check (%s)',
            $table,
            self::PREDICATE,
            self::PREDICATE,
        ));
    }

    public static function disable(string $table): void
    {
        DB::statement("drop policy if exists tenant_isolation on {$table}");
        DB::statement("alter table {$table} no force row level security");
        DB::statement("alter table {$table} disable row level security");
    }
}
