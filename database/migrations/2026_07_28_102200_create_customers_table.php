<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('nama');
            $table->string('no_hp')->nullable();
            $table->string('email')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->unsignedInteger('poin')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'nama']);
            $table->index(['tenant_id', 'no_hp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
