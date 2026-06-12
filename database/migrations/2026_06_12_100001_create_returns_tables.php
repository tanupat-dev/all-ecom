<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            // The Platform's Return Order ID — the dedup key (ADR 0006).
            $table->string('platform_return_id');
            $table->foreignId('ref_order_id')->constrained('orders');
            $table->string('return_type');
            $table->string('sub_status');
            $table->string('return_reason')->nullable();
            $table->string('buyer_note')->nullable();
            // Integer satang (ADR 0015).
            $table->bigInteger('refund_amount')->nullable();
            $table->string('tracking_number')->nullable();
            // When the buyer opened the case — the stale-flag anchor.
            $table->timestamp('requested_at')->nullable();
            $table->auditColumns();

            $table->unique(['tenant_id', 'shop_id', 'platform_return_id']);
            $table->index(['tenant_id', 'ref_order_id']);
            $table->index(['tenant_id', 'sub_status']);
        });

        Rls::enable('returns');

        Schema::create('return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('return_id')->constrained('returns');
            $table->foreignId('ref_order_line_id')->constrained('order_lines');
            $table->unsignedInteger('qty');
            $table->auditColumns();

            $table->unique(['tenant_id', 'return_id', 'ref_order_line_id']);
        });

        Rls::enable('return_lines');
    }

    public function down(): void
    {
        Schema::dropIfExists('return_lines');
        Schema::dropIfExists('returns');
    }
};
