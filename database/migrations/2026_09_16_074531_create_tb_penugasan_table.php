<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_penugasan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('surat_masuk_id')
                ->constrained('tb_surat_masuk')
                ->restrictOnDelete();

            $table->foreignId('ditugaskan_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('ditugaskan_kepada')
                ->constrained('users')
                ->restrictOnDelete();

            $table->enum('status', [
                'menunggu_respon',
                'diterima',
                'ditolak',
                'direkomendasikan',
                'selesai',
                'dibatalkan'
            ])->default('menunggu_respon');

            $table->text('catatan_penugasan')->nullable();

            $table->timestamp('waktu_penugasan')->nullable();
            $table->timestamp('waktu_respon')->nullable();

            $table->text('catatan_respon')->nullable();

            $table->timestamps();

            $table->index('surat_masuk_id');
            $table->index('ditugaskan_kepada');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_penugasan');
    }
};