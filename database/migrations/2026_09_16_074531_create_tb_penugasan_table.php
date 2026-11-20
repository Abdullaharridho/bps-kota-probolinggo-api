
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

            // Kegiatan yang ditugaskan
            $table->foreignId('kegiatan_id')
                ->constrained('tb_kegiatan')
                ->restrictOnDelete();

            // Pengguna yang memberikan penugasan
            $table->foreignId('ditugaskan_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            // Pegawai yang menerima penugasan
            $table->foreignId('ditugaskan_kepada')
                ->constrained('users')
                ->restrictOnDelete();

            // Status penugasan
            $table->enum('status', [
                'ditugaskan',
                'direkomendasikan',
                'proses',
                'selesai',
                'dibatalkan'
            ])->default('ditugaskan');

            // Catatan dari pemberi tugas
            $table->text('catatan_penugasan')->nullable();

            // Waktu penugasan dibuat
            $table->timestamp('waktu_penugasan')->nullable();

            $table->timestamps();

            // Index
            $table->index('kegiatan_id');
            $table->index('ditugaskan_kepada');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_penugasan');
    }
};
