<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->auditColumns();

            $table->index(['tenant_id', 'name']);
        });

        // Exactly one default Location per Tenant (ADR 0013).
        DB::statement('create unique index locations_one_default_per_tenant on locations (tenant_id) where is_default');

        Rls::enable('locations');
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
