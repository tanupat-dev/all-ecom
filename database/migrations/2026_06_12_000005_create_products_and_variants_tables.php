<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->auditColumns();

            $table->index(['tenant_id', 'name']);
        });

        Rls::enable('products');

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('master_sku');
            $table->string('name')->nullable();
            $table->string('barcode')->nullable();
            // Integer satang (ADR 0015).
            $table->bigInteger('list_price');
            $table->auditColumns();

            $table->unique(['tenant_id', 'master_sku']);
            $table->index(['tenant_id', 'product_id']);
        });

        // Barcode unique per tenant when present (CONVENTIONS rule 6).
        DB::statement('create unique index variants_tenant_barcode_unique on variants (tenant_id, barcode) where barcode is not null');

        Rls::enable('variants');
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
        Schema::dropIfExists('products');
    }
};
