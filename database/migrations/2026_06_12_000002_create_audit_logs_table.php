<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->jsonb('details')->nullable();
            $table->auditColumns();

            $table->index(['tenant_id', 'action']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });

        Rls::enable('audit_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
