<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One signed-up seller business — the top-level isolation boundary (ADR 0011).
 * The tenants table itself is the tenant registry: it carries no tenant_id and
 * no RLS policy. Signup/onboarding/billing are deferred (ROADMAP Phase 0).
 */
class Tenant extends Model
{
    protected $fillable = ['name'];
}
