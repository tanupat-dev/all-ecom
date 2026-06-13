<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claim Evidence Checklist table (CONTEXT.md: Claim, Evidence Checklist;
 * Issue #82). One row per proof item per Claim. The four default items are
 * seeded by SeedDefaultEvidence when a Claim is created; the seller may add
 * custom items afterward. `checked` is a mutable bool — this is a working
 * checklist, not an append-only ledger.
 *
 * tenant_id leads every index (ADR 0011). RLS enabled (ADR 0016).
 * auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 * claim_id cascades on delete (removing a Claim removes its evidence items).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_evidence_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->string('label');
            $table->boolean('checked')->default(false);
            $table->boolean('is_default')->default(false);
            $table->auditColumns();

            // Primary lookup: all evidence items for a tenant's Claim.
            $table->index(['tenant_id', 'claim_id']);
        });

        Rls::enable('claim_evidence_items');
    }

    public function down(): void
    {
        Rls::disable('claim_evidence_items');
        Schema::dropIfExists('claim_evidence_items');
    }
};
