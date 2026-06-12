<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('variant_id')->constrained();
            // Integer satang (ADR 0015).
            $table->bigInteger('cost');
            $table->timestamp('valid_from');
            $table->auditColumns();

            $table->index(['tenant_id', 'variant_id', 'valid_from']);
        });

        Rls::enable('cost_prices');
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_prices');
    }
};
