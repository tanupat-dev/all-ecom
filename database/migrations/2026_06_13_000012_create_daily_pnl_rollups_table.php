<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily P&L rollup (Issue #71): a queue-maintained summary so reports never
 * scan the raw Orders / Accounting lines / Payments / Expenses at request time
 * (ROADMAP Phase-1 scaling rule). One row per (tenant_id, shop_id, date), and
 * the row MUST always equal a from-raw recomputation (RecomputeDailyPnl).
 *
 * DATE BUCKET — P&L is recognised at the sale (the matching principle), not at
 * settlement: each row's `date` is the **Asia/Bangkok calendar date** of the
 * sale. Marketplace + POS Orders bucket by `created_date` (the sale moment,
 * stored UTC → converted to the Bangkok day); Expenses by `expense.date` (a
 * plain Bangkok calendar date); Cash Over/Short by the Shift's `closed_at`
 * (UTC → Bangkok day). Settlement Date is deliberately NOT used — that is when
 * money arrives, not when the sale is earned (ADR 0007).
 *
 * All money columns are integer satang (ADR 0015), never float / THB only.
 *   - marketplace_actual_net = Σ of the day's marketplace Orders' Actual Net.
 *   - fee_breakdown = jsonb map { AccountingLineCategory value → satang } — the
 *     per-category split of those same lines; Σ(fee_breakdown) == marketplace_actual_net.
 *   - pos_revenue / pos_cogs / pos_net — POS direct P&L (Payment − COGS), and
 *     pos_revenue − pos_cogs == pos_net by construction.
 *   - expense_total — operating Expenses attributable to this shop on the day.
 *   - cash_over_short — signed (shortage −, overage +).
 *
 * tenant_id leads every index (ADR 0011). RLS enabled (ADR 0016).
 * auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 * Mirrors 2026_06_13_000003_create_product_images_table.php in shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_pnl_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shop_id')->constrained('shops');
            $table->date('date');

            // Marketplace side (Actual Net + its per-category breakdown).
            $table->bigInteger('marketplace_actual_net')->default(0); // satang
            $table->jsonb('fee_breakdown')->default('{}'); // { category => satang }

            // POS side (direct P&L: Payment − COGS).
            $table->bigInteger('pos_revenue')->default(0); // satang
            $table->bigInteger('pos_cogs')->default(0);    // satang
            $table->bigInteger('pos_net')->default(0);     // satang
            // POS Orders excluded from the POS totals because a Variant had no
            // Cost Price at the sale date (#70 fail-loud) — the day's P&L is
            // incomplete by this many orders; surfaced by the combined report.
            $table->unsignedInteger('uncosted_pos_orders')->default(0);

            // Operating expenses + cash drawer over/short.
            $table->bigInteger('expense_total')->default(0);  // satang
            $table->bigInteger('cash_over_short')->default(0); // satang, signed

            $table->auditColumns();

            // One rollup row per shop per day, scoped to the tenant.
            $table->unique(['tenant_id', 'shop_id', 'date']);
            // Primary read path: a tenant's rollups over a date range.
            $table->index(['tenant_id', 'date']);
        });

        Rls::enable('daily_pnl_rollups');
    }

    public function down(): void
    {
        Rls::disable('daily_pnl_rollups');
        Schema::dropIfExists('daily_pnl_rollups');
    }
};
