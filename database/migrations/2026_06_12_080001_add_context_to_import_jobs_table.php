<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            // Caller-supplied context an Importer needs at run time and the
            // file itself cannot carry — e.g. which Shop a platform order
            // export belongs to (ROADMAP Phase 4).
            $table->jsonb('context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
