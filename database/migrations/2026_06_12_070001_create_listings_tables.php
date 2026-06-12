<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('product_id')->constrained();
            // Read-only reference fields the import hands us (CONTEXT.md:
            // Listing MVP scope — not content management).
            $table->string('category')->nullable();
            $table->string('image_url')->nullable();
            $table->auditColumns();

            $table->index(['tenant_id', 'shop_id']);
        });

        Rls::enable('listings');

        Schema::create('listing_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('listing_id')->constrained();
            // Denormalized from the listing: the importer's hot lookup is
            // (tenant, shop, platform_sku) → variant, O(1) by index.
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('variant_id')->constrained();
            $table->string('platform_sku');
            // Integer satang (ADR 0015).
            $table->bigInteger('deal_price')->nullable();
            $table->auditColumns();

            $table->index(['tenant_id', 'shop_id', 'platform_sku']);
            $table->unique(['tenant_id', 'listing_id', 'variant_id']);
        });

        Rls::enable('listing_variants');
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_variants');
        Schema::dropIfExists('listings');
    }
};
