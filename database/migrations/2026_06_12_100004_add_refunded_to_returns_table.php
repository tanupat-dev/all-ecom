<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // The fact that the Platform processed the refund — some
            // exports state it only as a status with no timestamp
            // (Lazada `Refunded`), so the Refund Status rollup cannot
            // hang on refunded_at alone.
            $table->boolean('refunded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('refunded');
        });
    }
};
