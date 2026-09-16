<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_laporan_kegiatan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('penugasan_id')
                ->constrained('tb_penugasan')
                ->restrictOnDelete();

            $table->text('ringkasan')->nullable();
            $table->text('hasil_kegiatan')->nullable();
            $table->text('catatan')->nullable();

            $table->foreignId('dilaporkan_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('tanggal_laporan')->nullable();

            $table->timestamps();

            $table->index('penugasan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_laporan_kegiatan');
    }
};