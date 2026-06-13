<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the nullable `reason_fault` column to `returns` — the pre-classified
 * fault bucket (CONTEXT.md: Return Reason; Issue #78). Null means the reason
 * is unrecognised and surfaced in the Unclassified Return Reasons list (ADR
 * 0005 fail-loud). Populated by ClassifyReturnReason on import.
 *
 * No RLS change: the `returns` table already has RLS enabled via its
 * original migration. No tenant_id index change needed — this is a simple
 * nullable column on an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // 'buyer_fault' | 'seller_fault' — cast to ReturnReasonFault on the model.
            $table->string('reason_fault')->nullable()->after('return_reason');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('reason_fault');
        });
    }
};
