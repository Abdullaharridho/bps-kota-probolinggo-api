<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_dokumen_kegiatan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('laporan_kegiatan_id')
                ->constrained('tb_laporan_kegiatan')
                ->cascadeOnDelete();

            $table->foreignId('diunggah_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            $table->enum('jenis_dokumen', [
                'materi',
                'dokumentasi',
                'notulen',
                'lainnya'
            ]);

            $table->string('nama_file');
            $table->string('path_file');

            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('ukuran_file')->nullable();

            $table->timestamps();

            $table->index('laporan_kegiatan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_dokumen_kegiatan');
    }
};