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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('register_id')->constrained();
            $table->string('status');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            // Integer satang (ADR 0015).
            $table->bigInteger('opening_float');
            $table->bigInteger('counted_cash')->nullable();
            $table->bigInteger('expected_cash')->nullable();
            $table->bigInteger('over_short')->nullable();
            $table->auditColumns();

            $table->index(['tenant_id', 'register_id']);
        });

        // At most one OPEN Shift per Register (CONTEXT.md: Shift).
        DB::statement("create unique index shifts_one_open_per_register on shifts (tenant_id, register_id) where status = 'open'");

        Rls::enable('shifts');

        Schema::create('shift_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shift_id')->constrained();
            $table->string('type');
            // Integer satang (ADR 0015); positive — direction lives in type.
            $table->bigInteger('amount');
            $table->string('reason');
            $table->auditColumns();

            $table->index(['tenant_id', 'shift_id']);
        });

        Rls::enable('shift_cash_movements');
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_cash_movements');
        Schema::dropIfExists('shifts');
    }
};
