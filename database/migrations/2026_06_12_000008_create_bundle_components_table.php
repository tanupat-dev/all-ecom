<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('bundle_variant_id')->constrained('variants');
            $table->foreignId('component_variant_id')->constrained('variants');
            $table->unsignedInteger('qty');
            $table->auditColumns();

            $table->unique(['tenant_id', 'bundle_variant_id', 'component_variant_id']);
        });

        Rls::enable('bundle_components');
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_components');
    }
};
