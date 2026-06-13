<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actual Net (CONTEXT.md: Actual Net; ADR 0007, Issue #61): the denormalized
 * sum of all signed amounts in the Order's Accounting Entry, recomputed in the
 * same transaction the lines are written. Signed satang; nullable = no
 * accounting imported yet. The read path — never SUM the lines at report time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('actual_net')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('actual_net');
        });
    }
};
