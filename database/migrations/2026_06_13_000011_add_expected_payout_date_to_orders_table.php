<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expected Payout Date (CONTEXT.md: Expected Payout Date; Issue #67):
 * the system-predicted date an Order's money will be settled into the
 * seller's Platform balance — `payout_anchor_date + hold_period` from
 * Shop Settings. Nullable: null when the anchor milestone is not yet set
 * (goods not yet delivered/completed) OR for a POS Order (no payout).
 * Recomputed by ComputeExpectedPayoutDate whenever milestones land on
 * import. Overdue detection = `expected_payout_date < now()` AND
 * `reconciliation_status = not_yet_paid`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('expected_payout_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('expected_payout_date');
        });
    }
};
