<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // A POS Return is a negative-line Order linked to its original
            // sale (ADR 0009).
            $table->foreignId('ref_order_id')->nullable()->constrained('orders');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ref_order_id');
        });
    }
};
