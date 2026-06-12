<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('variant_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->string('action');
            $table->integer('qty_delta');
            // SHIP only: the reservation that order actually held (POS = 0).
            $table->integer('reserved_released')->nullable();
            $table->nullableMorphs('ref');
            $table->string('note')->nullable();
            $table->auditColumns();

            $table->index(['tenant_id', 'variant_id', 'location_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Rls::enable('stock_movements');

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('variant_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('damaged')->default(0);
            $table->integer('buffer')->default(0);
            $table->auditColumns();

            $table->unique(['tenant_id', 'variant_id', 'location_id']);
        });

        Rls::enable('stock_balances');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_movements');
    }
};
