<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product Images table (ADR 0019, Issue #47): channel-agnostic image storage
 * for a Product, normalised to 1:1 JPEG and hosted on object storage so that
 * Channel Upload Template fill can write public URLs into image columns.
 *
 * tenant_id leads every index (ADR 0011). RLS enabled (ADR 0016).
 * auditColumns() — created_at / updated_at / created_by (CONVENTIONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variant_id')->nullable()->constrained('variants');
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->auditColumns();

            // Primary lookup: all images for a product within a tenant.
            $table->index(['tenant_id', 'product_id']);
        });

        Rls::enable('product_images');
    }

    public function down(): void
    {
        Rls::disable('product_images');
        Schema::dropIfExists('product_images');
    }
};
