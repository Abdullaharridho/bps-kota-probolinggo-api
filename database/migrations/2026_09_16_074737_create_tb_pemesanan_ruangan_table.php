<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_pemesanan_ruangan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ruangan_id')
                ->constrained('tb_ruangan')
                ->restrictOnDelete();

            $table->foreignId('penugasan_id')
                ->nullable()
                ->constrained('tb_penugasan')
                ->nullOnDelete();

            $table->foreignId('dipesan_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('judul_kegiatan');

            $table->date('tanggal');
            $table->time('waktu_mulai');
            $table->time('waktu_selesai');

            $table->enum('status', [
                'dipesan',
                'digunakan',
                'selesai',
                'dibatalkan'
            ])->default('dipesan');

            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->index([
                'ruangan_id',
                'tanggal'
            ]);

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_pemesanan_ruangan');
    }
};