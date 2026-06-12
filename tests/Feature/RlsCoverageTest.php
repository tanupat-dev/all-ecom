<?php

use Illuminate\Support\Facades\DB;

/**
 * The fail-loud net behind ADR 0011/0016: a migration that ships a domain
 * table without tenant_id or without Rls::enable() fails this suite — no
 * sensitive table ships without isolation (Issue #7).
 *
 * Framework / infrastructure tables that legitimately carry no tenant_id.
 * Adding a table here is a reviewed decision, not a default.
 *
 * @return list<string>
 */
function rlsExemptTables(): array
{
    return [
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'sessions',
        'tenants', // the tenant registry itself
        'users', // carries tenant_id but stays RLS-free (auth resolves pre-context)
        'permissions', // the system-defined global catalogue (ADR 0012) — not tenant data
        'role_has_permissions', // keyed by role; the role row itself is RLS-scoped
    ];
}

it('gives every non-exempt table a tenant_id column', function () {
    $missing = collect(DB::select(<<<'SQL'
        select t.table_name
        from information_schema.tables t
        where t.table_schema = 'public' and t.table_type = 'BASE TABLE'
          and not exists (
            select 1 from information_schema.columns c
            where c.table_schema = 'public'
              and c.table_name = t.table_name
              and c.column_name = 'tenant_id'
          )
        SQL))
        ->pluck('table_name')
        ->diff(rlsExemptTables());

    expect($missing->all())->toBe([]);
});

it('enables and forces RLS on every table that has tenant_id', function () {
    // users carries tenant_id (Phase 2 tie) but stays RLS-free: auth must
    // resolve the user BEFORE any tenant context exists to read it.
    $rlsFreeWithTenantId = ['users'];

    $unprotected = collect(DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r'
          and exists (
            select 1 from information_schema.columns col
            where col.table_schema = 'public'
              and col.table_name = c.relname
              and col.column_name = 'tenant_id'
          )
          and not (c.relrowsecurity and c.relforcerowsecurity)
        SQL))->pluck('relname')->diff($rlsFreeWithTenantId);

    expect($unprotected->all())->toBe([]);
});
