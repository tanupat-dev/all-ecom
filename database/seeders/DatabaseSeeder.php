<?php

namespace Database\Seeders;

use App\Actions\Tenants\CreateTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    // No WithoutModelEvents here: the tenancy/audit creating-hooks
    // (BelongsToTenant, TracksCreatedBy) MUST run while seeding.

    /**
     * Seed the application's database. Idempotent — safe to re-run.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->firstWhere('name', 'ร้านเดโม่')
            ?? app(CreateTenant::class)->handle('ร้านเดโม่');

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@all-ecom.test'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
            ],
        );

        app(TenantContext::class)->set($tenant);

        try {
            $admin->assignRole('Admin');
        } finally {
            app(TenantContext::class)->forget();
        }
    }
}
