<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting Entry lines (CONTEXT.md: Accounting Entry; ADR 0007, Issue #61):
 * the per-Order financial line items built from a Platform's accounting Excel.
 * Each row maps one Platform-native fee field (source_field) to one Fee
 * Category, with a signed satang amount (+ = seller receives, − = seller pays)
 * and the statement_cycle it came from.
 *
 * Import is cycle-aware (ADR 0007): the unique key
 * (order_id, statement_cycle, source_field) is the idempotency boundary —
 * re-importing the same cycle replaces that cycle's lines, a new cycle
 * appends. tenant_id leads the lookup index (ADR 0011). RLS enabled (ADR
 * 0016). auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('order_id')->constrained('orders');
            $table->string('statement_cycle');
            $table->string('source_field');
            $table->string('category');
            $table->bigInteger('amount');
            $table->auditColumns();

            // Primary lookup: all accounting lines for an Order within a tenant.
            $table->index(['tenant_id', 'order_id']);

            // The cycle-aware idempotency boundary (ADR 0007): one line per
            // Platform field per cycle per Order.
            $table->unique(['order_id', 'statement_cycle', 'source_field']);
        });

        Rls::enable('accounting_entry_lines');
    }

    public function down(): void
    {
        Rls::disable('accounting_entry_lines');
        Schema::dropIfExists('accounting_entry_lines');
    }
};
