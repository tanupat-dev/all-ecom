<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->string('platform');
            $table->string('platform_type');
            $table->foreignId('location_id')->constrained();
            $table->auditColumns();

            $table->index(['tenant_id', 'platform_type']);
        });

        Rls::enable('shops');

        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->unsignedInteger('hold_period');
            $table->string('payout_anchor');
            // Integer satang (ADR 0015).
            $table->bigInteger('mismatch_threshold');
            $table->jsonb('expected_shipping_rate')->nullable();
            $table->auditColumns();

            $table->unique(['tenant_id', 'shop_id']);
        });

        Rls::enable('shop_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
        Schema::dropIfExists('shops');
    }
};
