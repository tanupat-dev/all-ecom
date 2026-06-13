<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claims table (CONTEXT.md: Claim; Issue #79): the kernel every other Claim
 * slice hangs off. A Claim always attaches to one Order (ref_order_id, never
 * null); a `return_fee` Claim additionally to the Return that triggered it
 * (ref_return_id, nullable). No money column — payouts live on the Timeline.
 *
 * tenant_id leads every index (ADR 0011). RLS enabled (ADR 0016).
 * auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 * claim_type / status persisted as strings, cast to enums on the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('claim_type');
            $table->string('status');
            $table->foreignId('ref_order_id')->constrained('orders');
            $table->foreignId('ref_return_id')->nullable()->constrained('returns');
            $table->auditColumns();

            // Primary lookups: a tenant's open Claims, and Claims for an Order.
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'ref_order_id']);
        });

        Rls::enable('claims');
    }

    public function down(): void
    {
        Rls::disable('claims');
        Schema::dropIfExists('claims');
    }
};
