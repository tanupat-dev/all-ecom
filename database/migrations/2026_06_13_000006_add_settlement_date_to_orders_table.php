<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement Date (CONTEXT.md: Settlement Date; ADR 0007/0020): the date the
 * Platform actually transferred an Order's money into the seller's balance,
 * read from the accounting Excel. Nullable = not yet settled / not yet
 * imported. Set no-null-overwrite by the accounting importer (same spirit as
 * the ADR 0004 milestone upsert). The orders table already carries tenant_id
 * + RLS, so this column inherits the existing isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'settlement_date')) {
                $table->timestamp('settlement_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'settlement_date')) {
                $table->dropColumn('settlement_date');
            }
        });
    }
};
