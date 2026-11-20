<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_kegiatan', function (Blueprint $table) {
            $table->id();

            // Relasi tim
            $table->foreignId('tim_id')
                ->constrained('tb_tim')
                ->restrictOnDelete();

            $table->string('nama_kegiatan');
            $table->text('uraian_kegiatan')->nullable();

            // Lokasi pelaksanaan
            $table->text('lokasi');

            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');

            $table->decimal('volume_jam', 5, 2);

            $table->time('jam_mulai')->nullable();
            $table->time('jam_selesai')->nullable();

            $table->enum('status', [
                'baru',
                'proses',
                'selesai'
            ])->default('baru');

            $table->timestamps();

            $table->index('tanggal_mulai');
            $table->index('tanggal_selesai');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_kegiatan');
    }
};