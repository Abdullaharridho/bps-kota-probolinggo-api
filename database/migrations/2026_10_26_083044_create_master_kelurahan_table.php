<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_kelurahan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kecamatan_id')
                ->constrained('master_kecamatan')
                ->cascadeOnDelete();

            $table->string('kode_kelurahan', 30);
            $table->string('nama_kelurahan', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['kecamatan_id', 'kode_kelurahan'],
                'kelurahan_kecamatan_kode_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_kelurahan');
    }
};