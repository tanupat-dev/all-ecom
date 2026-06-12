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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->string('platform_type');
            $table->string('platform_order_id')->nullable();
            $table->string('status');
            // Integer satang (ADR 0015).
            $table->bigInteger('total');
            $table->string('tracking_number')->nullable();
            // Buyer PII minimal (security checklist): name/phone only.
            $table->string('buyer_name')->nullable();
            $table->string('buyer_phone')->nullable();
            // The 6 flat milestones (ADR 0004).
            $table->timestamp('created_date')->nullable();
            $table->timestamp('paid_date')->nullable();
            $table->timestamp('shipped_date')->nullable();
            $table->timestamp('delivered_date')->nullable();
            $table->timestamp('completed_date')->nullable();
            $table->timestamp('cancelled_date')->nullable();
            $table->auditColumns();

            $table->index(['tenant_id', 'shop_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Import dedup key (ADR 0004 upserts): unique where the platform id exists.
        DB::statement('create unique index orders_platform_dedup on orders (tenant_id, shop_id, platform_order_id) where platform_order_id is not null');

        Rls::enable('orders');

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('variant_id')->constrained();
            $table->unsignedInteger('qty');
            // Integer satang (ADR 0015).
            $table->bigInteger('unit_price');
            $table->bigInteger('line_total');
            $table->auditColumns();

            $table->index(['tenant_id', 'order_id']);
        });

        Rls::enable('order_lines');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
