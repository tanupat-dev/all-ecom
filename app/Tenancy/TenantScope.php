<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The application layer of the two-layer isolation (ADR 0011). With no tenant
 * context it applies no filter (system code may run); the RLS layer below
 * still fails closed (ADR 0018).
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class)->current();

        if ($tenant !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenant->id);
        }
    }
}
