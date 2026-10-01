
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_kegiatan_harian', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('tanggal');
            $table->string('nama_kegiatan', 255);
            $table->time('jam_mulai');
            $table->time('jam_selesai');

            $table->timestamps();

            $table->index(
                ['user_id', 'tanggal'],
                'idx_kegiatan_user_tanggal'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_kegiatan_harian');
    }
};