<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->string('tender_type');
            // Integer satang (ADR 0015); negative = refund line (ADR 0009).
            $table->bigInteger('amount');
            $table->auditColumns();

            $table->index(['tenant_id', 'order_id']);
        });

        Rls::enable('payments');

        // POS columns on the unified orders table (ADR 0002 nullable budget).
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->constrained();
            $table->unsignedInteger('receipt_no')->nullable();
            // Cart-level Manual Discount, satang (line discounts live on lines).
            $table->bigInteger('cart_discount')->default(0);
        });

        Schema::table('order_lines', function (Blueprint $table) {
            // Line-level Manual Discount, satang, already reflected in line_total.
            $table->bigInteger('discount')->default(0);
        });

        // receipt_no runs sequentially per pos Shop (CONTEXT.md: Receipt).
        Schema::create('receipt_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->unsignedInteger('last_number')->default(0);
            $table->auditColumns();

            $table->unique(['tenant_id', 'shop_id']);
        });

        Rls::enable('receipt_counters');
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_counters');
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn(['receipt_no', 'cart_discount']);
        });
        Schema::dropIfExists('payments');
    }
};
