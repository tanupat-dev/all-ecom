<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses table (CONTEXT.md: Expense; Issue #69): operating costs entered
 * manually by the seller — packaging, rent, staff, etc. Not Cost Price and
 * not a Platform fee.
 *
 * tenant_id leads every index (ADR 0011). RLS enabled (ADR 0016).
 * auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 * amount = integer satang, never float (ADR 0015).
 * category = free-form string per CONTEXT.md — not an enum.
 * ref_order_id = optional FK for per-order attributable costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->date('date');
            $table->string('category');
            $table->bigInteger('amount'); // integer satang (ADR 0015)
            $table->text('note')->nullable();
            $table->foreignId('ref_order_id')->nullable()->constrained('orders');
            $table->auditColumns();

            // Primary lookup: tenant expenses by date.
            $table->index(['tenant_id', 'date']);
        });

        Rls::enable('expenses');
    }

    public function down(): void
    {
        Rls::disable('expenses');
        Schema::dropIfExists('expenses');
    }
};
