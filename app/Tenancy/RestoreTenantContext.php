<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * Job middleware: a queue worker has no request, so a tenant-scoped job
 * must restore its Tenant before touching any RLS-protected table (which
 * fails closed otherwise). Capture the tenant id at dispatch time and add
 * `new RestoreTenantContext($this->tenantId)` in the job's middleware().
 */
class RestoreTenantContext
{
    public function __construct(
        private readonly int $tenantId,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $context = app(TenantContext::class);

        // A sync dispatch runs inside the caller's request — put the
        // caller's tenant back afterwards instead of clearing it.
        $previous = $context->current();
        $context->set(Tenant::query()->findOrFail($this->tenantId));

        try {
            $next($job);
        } finally {
            $previous !== null ? $context->set($previous) : $context->forget();
        }
    }
}
