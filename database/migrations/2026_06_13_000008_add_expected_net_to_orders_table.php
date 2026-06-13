<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expected Net (CONTEXT.md: Expected Net; Issue #65): the Effective Price net
 * of the Shop's Platform Fee Profile — the forward-looking number the seller
 * expects to receive, checked later against Actual Net. Signed satang
 * (ADR 0015); nullable = not yet computed. Denormalized onto the Order and
 * recomputed by ComputeExpectedNet — the read path never recalculates fees
 * at report time. Marketplace Orders only (a POS Order never gets one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('expected_net')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('expected_net');
        });
    }
};
