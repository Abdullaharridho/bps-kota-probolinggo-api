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
                ->unique()
                ->constrained('tb_penugasan')
                ->restrictOnDelete();

            $table->text('catatan');

            $table->foreignId('dilaporkan_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('tanggal_laporan')->useCurrent();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_laporan_kegiatan');
    }
};