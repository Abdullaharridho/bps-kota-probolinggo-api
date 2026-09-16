<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_rekomendasi_penugasan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('penugasan_id')
                ->constrained('tb_penugasan')
                ->restrictOnDelete();

            $table->foreignId('pegawai_asal_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('pegawai_rekomendasi_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('alasan')->nullable();

            $table->enum('status', [
                'menunggu_review',
                'diterima',
                'ditolak'
            ])->default('menunggu_review');

            $table->foreignId('diproses_oleh')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('diproses_pada')->nullable();

            $table->timestamps();

            $table->index('penugasan_id');
            $table->index('pegawai_rekomendasi_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_rekomendasi_penugasan');
    }
};