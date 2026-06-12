<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Return Sub-Status is shared vocabulary at Order level for a
            // whole-package ตีกลับ (CONTEXT.md: Return Sub-Status; ADR 0006).
            $table->string('return_sub_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('return_sub_status');
        });
    }
};
