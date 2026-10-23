
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_kode_rumah_log', function (Blueprint $table) {
            $table->id();

            // Relasi ke data rumah
            $table->foreignId('kode_rumah_id')
                ->nullable()
                ->constrained('tb_kode_rumah')
                ->nullOnDelete();

            // Jenis aktivitas
            $table->enum('aksi', [
                'tambah',
                'ubah',
                'hapus',
            ]);

            // Snapshot data sebelum dan sesudah perubahan
            $table->json('data_sebelum')->nullable();
            $table->json('data_sesudah')->nullable();

            // Pengguna yang melakukan aktivitas
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Alasan atau keterangan perubahan
            $table->text('keterangan')->nullable();

            // Waktu pencatatan log
            $table->timestamp('created_at')->useCurrent();

            // Index riwayat rumah dan aktivitas pengguna
            $table->index(
                ['kode_rumah_id', 'created_at'],
                'idx_kode_rumah_log_riwayat'
            );

            $table->index(
                ['user_id', 'created_at'],
                'idx_kode_rumah_log_user'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_kode_rumah_log');
    }
};
