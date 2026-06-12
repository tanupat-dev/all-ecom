<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('importer');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('status')->default('pending');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->jsonb('errors')->nullable();
            $table->auditColumns();

            $table->index(['tenant_id', 'status']);
        });

        Rls::enable('import_jobs');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
