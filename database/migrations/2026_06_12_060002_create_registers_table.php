<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->auditColumns();

            $table->index(['tenant_id', 'shop_id']);
        });

        Rls::enable('registers');
    }

    public function down(): void
    {
        Schema::dropIfExists('registers');
    }
};
