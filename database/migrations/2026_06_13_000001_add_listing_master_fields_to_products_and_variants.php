<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Channel-agnostic listing fields on Product (ADR 0019, Issue #46).
        // These are authored once and shared across every Platform — they
        // feed the Channel Upload Template fill engine, not per-channel
        // overrides. No per-channel content here.
        Schema::table('products', function (Blueprint $table) {
            $table->string('english_name')->nullable()->after('name');
            $table->text('description')->nullable()->after('english_name');
            $table->string('brand')->nullable()->after('description');
        });

        // Package dimensions on Variant (ADR 0019, Issue #46). Integers in
        // grams / millimetres — mirrors the integer-satang no-float rule
        // (ADR 0015). Platform-unit conversion (kg, cm) happens at fill
        // time, NOT here.
        Schema::table('variants', function (Blueprint $table) {
            $table->unsignedInteger('package_weight_g')->nullable()->after('barcode');
            $table->unsignedInteger('package_width_mm')->nullable()->after('package_weight_g');
            $table->unsignedInteger('package_length_mm')->nullable()->after('package_width_mm');
            $table->unsignedInteger('package_height_mm')->nullable()->after('package_length_mm');
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->dropColumn(['package_weight_g', 'package_width_mm', 'package_length_mm', 'package_height_mm']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['english_name', 'description', 'brand']);
        });
    }
};
