<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Fee Profile (CONTEXT.md: Platform Fee Profile; Issue #65): the
 * expected/predicted fee rates the system applies per Shop when estimating
 * the seller's net receivable on a sale — the forward-looking Expected Net,
 * distinct from the Accounting Entry's backward-looking Actual Net.
 *
 * One row per (Shop, fee-side Accounting Line Category). `rate_bps` is the
 * expected fee rate in basis points (integer — 321 = 3.21%, never float,
 * ADR 0015); `fixed_satang` is an optional flat per-order fee in signed
 * satang (a raw integer added directly to the fee total, not a Money cast).
 *
 * tenant_id leads every index (ADR 0011). RLS enabled (ADR 0016).
 * auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shop_id')->constrained('shops');
            $table->string('category'); // an AccountingLineCategory fee-side value
            $table->integer('rate_bps')->default(0); // basis points: 321 = 3.21%
            $table->bigInteger('fixed_satang')->default(0); // signed satang flat fee
            $table->auditColumns();

            // Primary lookup: all fee rows for a Shop within a tenant.
            $table->index(['tenant_id', 'shop_id']);

            // One expected rate per category per Shop (a Shop is tenant-scoped,
            // so this is unique-per-business without restating tenant_id).
            $table->unique(['shop_id', 'category']);
        });

        Rls::enable('platform_fee_profiles');
    }

    public function down(): void
    {
        Rls::disable('platform_fee_profiles');
        Schema::dropIfExists('platform_fee_profiles');
    }
};
