<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // When the Platform processed the refund (Shopee เวลาที่คืนเงิน,
            // TikTok Refund Time) — splits รอคืน from คืนแล้ว in the
            // Refund Status rollup (CONTEXT.md).
            $table->timestamp('refunded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('refunded_at');
        });
    }
};
