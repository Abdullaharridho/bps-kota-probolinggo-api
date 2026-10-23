
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_kode_rumah', function (Blueprint $table) {
            $table->id();

            // Informasi wilayah
            $table->string('nama_kecamatan', 100);
            $table->string('nama_kelurahan', 100);
            $table->string('nama_rt_rw', 100);

            // Kode pencarian rumah
            $table->string('idsubsls', 30);
            $table->string('no_bangunan', 20);

            // Koordinat rumah
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Informasi kepala keluarga dan usaha
            $table->string('nama_kk')->nullable();
            $table->string('nama_usaha')->nullable();

            // Status data
            $table->enum('status_data', [
                'awal',
                'baru',
                'edited',
            ])->default('awal');

            // Pengguna yang membuat data
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Pengguna yang terakhir mengubah data
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Filter wilayah bertingkat
            $table->index(
                ['nama_kecamatan', 'nama_kelurahan', 'nama_rt_rw'],
                'idx_kode_rumah_nama_wilayah'
            );

            // Pencarian kode Sub-SLS
            $table->index(
                'idsubsls',
                'idx_kode_rumah_subsls'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_kode_rumah');
    }
};
