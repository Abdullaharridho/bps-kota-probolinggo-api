<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ruangan', function (Blueprint $table) {
            $table->id();

            $table->string('nama_ruangan');
            $table->string('lokasi')->nullable();

            $table->unsignedInteger('kapasitas')->nullable();

            $table->text('keterangan')->nullable();

            $table->enum('status', [
                'aktif',
                'nonaktif'
            ])->default('aktif');

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ruangan');
    }
};