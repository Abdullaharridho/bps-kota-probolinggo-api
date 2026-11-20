
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

            // Relasi ke laporan kegiatan
            $table->foreignId('laporan_kegiatan_id')
                ->constrained('tb_laporan_kegiatan')
                ->cascadeOnDelete();

            // Pengunggah foto
            $table->foreignId('diunggah_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            // Informasi foto
            $table->string('nama_file');
            $table->string('path_file');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('ukuran_file')->nullable();

            // Lokasi pengambilan dokumentasi
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_dokumen_kegiatan');
    }
};
