<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claim Timeline entries (CONTEXT.md: Claim — Claim Timeline; Issue #83): an
 * append-only log of the manual actions taken on a Claim — submission, the
 * Platform's decisions, info requests during the ticket stage, evidence
 * updates and payout amounts. The system's Phase-0 ledger pattern: rows are
 * only ever appended, never updated or deleted — a correction is a new entry.
 *
 * payout_amount is integer satang (ADR 0015), nullable — only "won ฿X" entries
 * carry money. tenant_id leads every index (ADR 0011). RLS enabled (ADR 0016).
 * auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_timeline_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->dateTime('occurred_at');
            $table->string('action');
            $table->text('note')->nullable();
            $table->string('ticket_no')->nullable();
            // Integer satang (ADR 0015) — cast to Money on the model. Nullable:
            // only "won ฿X" entries carry a payout.
            $table->bigInteger('payout_amount')->nullable();
            $table->auditColumns();

            // Primary lookup: a tenant's entries for one Claim, in time order.
            $table->index(['tenant_id', 'claim_id', 'occurred_at']);
        });

        Rls::enable('claim_timeline_entries');
    }

    public function down(): void
    {
        Rls::disable('claim_timeline_entries');
        Schema::dropIfExists('claim_timeline_entries');
    }
};
