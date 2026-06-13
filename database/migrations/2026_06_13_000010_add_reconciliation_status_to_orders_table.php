<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation Status (CONTEXT.md: Reconciliation Status; ADR 0007, Issue
 * #66): the per-Order flag comparing Actual Net to Expected Net within the
 * Shop's Mismatch Threshold. String-backed enum (ReconciliationStatus);
 * nullable = not yet graded. Marketplace Orders only — a POS Order leaves it
 * null (the compute Action refuses one). Recomputed by
 * ComputeReconciliationStatus whenever either money edge moves (a late
 * accounting cycle or a Fee Profile change) — the read path never recomputes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('reconciliation_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('reconciliation_status');
        });
    }
};
