<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The reusable cross-tenant isolation harness (ADR 0011, Issue #7): every
 * tenant-scoped table calls this from its test file. $makeRow must persist
 * and return one row for the CURRENT tenant context.
 *
 * Proves, for the model's table:
 *  - the app scope shows each tenant only its own rows
 *  - RLS returns zero rows with no tenant context (fails closed)
 *  - RLS blocks reading / updating / deleting the other tenant's row even
 *    when the query bypasses Eloquent
 *  - RLS rejects inserting a row for another tenant (WITH CHECK)
 *
 * @param  Closure(): Model  $makeRow
 */
function assertTenantIsolation(Closure $makeRow): void
{
    $context = app(TenantContext::class);

    $a = Tenant::query()->create(['name' => 'isolation-harness-a']);
    $b = Tenant::query()->create(['name' => 'isolation-harness-b']);

    $context->set($a);
    $rowA = $makeRow();

    $context->set($b);
    $rowB = $makeRow();

    expect($rowA::class)->toBe($rowB::class);
    $table = $rowA->getTable();
    $keyName = $rowA->getKeyName();

    // App scope: each tenant sees exactly its own row.
    $context->set($a);
    expect($rowA->newQuery()->pluck($keyName)->all())->toBe([$rowA->getKey()]);

    $context->set($b);
    expect($rowB->newQuery()->pluck($keyName)->all())->toBe([$rowB->getKey()]);

    // RLS fails closed with no tenant context, even bypassing Eloquent.
    $context->forget();
    expect(DB::table($table)->count())->toBe(0);

    // RLS blocks cross-tenant read / update / delete on raw queries.
    $context->set($a);
    expect(DB::table($table)->where($keyName, $rowB->getKey())->exists())->toBeFalse()
        ->and(DB::table($table)->where($keyName, $rowB->getKey())->update(['tenant_id' => $a->id]))->toBe(0)
        ->and(DB::table($table)->where($keyName, $rowB->getKey())->delete())->toBe(0);

    // RLS rejects planting a row under the other tenant (WITH CHECK). The
    // savepoint keeps the calling test's transaction usable afterwards.
    $planted = collect($rowA->getAttributes())
        ->except([$keyName])
        ->merge(['tenant_id' => $b->id])
        ->all();

    $blocked = false;

    try {
        DB::transaction(function () use ($table, $planted): void {
            DB::table($table)->insert($planted);
        });
    } catch (QueryException $e) {
        $blocked = true;
        expect($e->getMessage())->toContain('row-level security policy');
    }

    expect($blocked)->toBeTrue('Expected the cross-tenant insert to violate the RLS policy.');

    // The other tenant's row was never touched.
    $context->set($b);
    expect(DB::table($table)->where($keyName, $rowB->getKey())->where('tenant_id', $b->id)->exists())->toBeTrue();

    $context->forget();
}
