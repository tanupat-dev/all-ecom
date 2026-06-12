<?php

use Illuminate\Support\Facades\DB;

/**
 * Companion guard to RlsCoverageTest (CONVENTIONS DB rules): every domain
 * table records created_at/updated_at + created_by — use the auditColumns()
 * Blueprint macro. Framework tables and pre-Phase-2 identity tables are
 * exempt; adding one here is a reviewed decision.
 *
 * @return list<string>
 */
function auditColumnsExemptTables(): array
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
        'tenants', // created at signup (deferred) — re-visit with onboarding
        'users', // reshaped at Phase 2 (User ↔ Tenant tie)
    ];
}

it('gives every non-exempt table the audit columns', function () {
    $missing = collect(DB::select(<<<'SQL'
        select t.table_name
        from information_schema.tables t
        where t.table_schema = 'public' and t.table_type = 'BASE TABLE'
          and not (
            exists (select 1 from information_schema.columns c
                    where c.table_schema = 'public' and c.table_name = t.table_name and c.column_name = 'created_at')
            and exists (select 1 from information_schema.columns c
                    where c.table_schema = 'public' and c.table_name = t.table_name and c.column_name = 'updated_at')
            and exists (select 1 from information_schema.columns c
                    where c.table_schema = 'public' and c.table_name = t.table_name and c.column_name = 'created_by')
          )
        SQL))
        ->pluck('table_name')
        ->diff(auditColumnsExemptTables());

    expect($missing->all())->toBe([]);
});
