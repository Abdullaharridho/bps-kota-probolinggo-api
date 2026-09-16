<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_notifikasi', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pengguna_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->enum('jenis', [
                'penugasan_baru',
                'penugasan_diterima',
                'penugasan_ditolak',
                'rekomendasi_penugasan',
                'penugasan_baru_setelah_rekomendasi',
                'booking_ruangan',
                'booking_dibatalkan',
                'reminder_h1',
                'reminder_h',
                'laporan_kegiatan'
            ]);

            $table->enum('channel', [
                'whatsapp',
                'aplikasi',
                'email'
            ])->default('whatsapp');

            $table->string('judul');
            $table->text('pesan');

            $table->string('referensi_tipe')->nullable();
            $table->unsignedBigInteger('referensi_id')->nullable();

            $table->enum('status', [
                'pending',
                'processing',
                'sent',
                'failed'
            ])->default('pending');

            $table->timestamp('jadwal_kirim')->nullable();
            $table->timestamp('terkirim_pada')->nullable();
            $table->timestamp('gagal_pada')->nullable();

            $table->text('pesan_error')->nullable();

            $table->timestamps();

            $table->index([
                'status',
                'jadwal_kirim'
            ]);

            $table->index([
                'referensi_tipe',
                'referensi_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_notifikasi');
    }
};