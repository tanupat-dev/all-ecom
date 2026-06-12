<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // CONTEXT.md: Cancellation Reason — canonical bucket + raw
            // source, captured only when the Order is ยกเลิก.
            $table->string('cancelled_by')->nullable();
            $table->string('cancel_reason_category')->nullable();
            $table->string('cancel_reason_source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by', 'cancel_reason_category', 'cancel_reason_source']);
        });
    }
};
