<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_surat_masuk', function (Blueprint $table) {
            $table->id();

            $table->string('nomor_surat');
            $table->date('tanggal_surat');
            $table->date('tanggal_diterima');

            $table->string('asal_surat');
            $table->string('perihal');

            $table->text('isi_ringkas')->nullable();

            $table->date('tanggal_acara')->nullable();
            $table->time('waktu_mulai')->nullable();
            $table->time('waktu_selesai')->nullable();

            $table->string('lokasi')->nullable();

            $table->string('file_surat')->nullable();

            $table->enum('status', [
                'baru',
                'diproses',
                'selesai',
                'dibatalkan'
            ])->default('baru');

            $table->foreignId('dicatat_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index('tanggal_acara');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_surat_masuk');
    }
};