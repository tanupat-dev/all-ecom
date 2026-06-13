<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions + Promotion Lines kernel (CONTEXT.md: Promotion, Promotion Line;
 * ADR 0021). A Promotion is a `base` markdown or a time-bounded `campaign`
 * (the `type` discriminator + nullable start_at/end_at window). Each Promotion
 * Line is one Variant × Listing carrying its own Deal Price (integer satang).
 *
 * A Promotion has NO shop_id: per CONTEXT one Promotion may span multiple
 * Shops/Platforms — its Shop scope is the set of Shops its lines touch (each
 * line's listing_variant carries the denormalized shop_id). The "at most one
 * active base Promotion per Shop" invariant is therefore evaluated per the
 * Shops the lines touch, enforced fail-loud in CreatePromotion (ADR 0021).
 *
 * tenant_id leads every index (ADR 0011). RLS enabled per table (ADR 0016).
 * auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            // PromotionType: 'base' | 'campaign'.
            $table->string('type');
            // base has neither; campaign has both (start_at < end_at).
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->auditColumns();

            // Primary lookup: this tenant's base/campaign Promotions.
            $table->index(['tenant_id', 'type']);
        });

        Rls::enable('promotions');

        Schema::create('promotion_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('promotion_id')->constrained('promotions');
            $table->foreignId('listing_variant_id')->constrained('listing_variants');
            // Deal Price as integer satang (ADR 0015) — the authority for the
            // Listing-Variant's Effective Price (ADR 0021).
            $table->bigInteger('deal_price');
            $table->auditColumns();

            $table->index(['tenant_id', 'promotion_id']);
            // One line per Variant × Listing per Promotion. promotion_id is
            // tenant-bound, so leading with tenant_id keeps the convention
            // without changing which rows collide.
            $table->unique(['tenant_id', 'promotion_id', 'listing_variant_id']);
        });

        Rls::enable('promotion_lines');
    }

    public function down(): void
    {
        Rls::disable('promotion_lines');
        Schema::dropIfExists('promotion_lines');

        Rls::disable('promotions');
        Schema::dropIfExists('promotions');
    }
};
