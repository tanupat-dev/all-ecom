<?php

namespace App\Actions\Tenants;

use App\Authorization\PermissionCatalogue;
use App\Models\Location;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Creates a Tenant with its auto-provisioned default Location (ADR 0013 —
 * a single-site seller never sees the Location concept, but the dimension
 * exists from day one). Signup/onboarding UI is deferred (ADR 0011); tests,
 * seeders, and Phase-2 onboarding all create Tenants through this.
 */
class CreateTenant
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(string $name): Tenant
    {
        return DB::transaction(function () use ($name): Tenant {
            $tenant = Tenant::query()->create(['name' => $name]);

            $previous = $this->context->current();
            $this->context->set($tenant);

            try {
                Location::query()->create([
                    'name' => 'คลังหลัก',
                    'is_default' => true,
                ]);

                // The two editable default Roles every Tenant starts with
                // (ADR 0012): Admin = everything, Cashier = the POS subset.
                PermissionCatalogue::ensureSeeded();

                Role::findOrCreate('Admin', 'web')
                    ->syncPermissions(PermissionCatalogue::ALL);
                Role::findOrCreate('Cashier', 'web')
                    ->syncPermissions(PermissionCatalogue::CASHIER);
            } finally {
                $previous !== null ? $this->context->set($previous) : $this->context->forget();
            }

            return $tenant;
        });
    }
}
