<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_rtrw', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kelurahan_id')
                ->constrained('master_kelurahan')
                ->cascadeOnDelete();

            $table->string('kode_wilayah', 50);
            $table->string('nama_wilayah', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['kelurahan_id', 'kode_wilayah'],
                'rtrw_kelurahan_kode_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_rtrw');
    }
};