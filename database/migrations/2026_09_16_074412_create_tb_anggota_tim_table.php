<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_anggota_tim', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tim_id')
                ->constrained('tb_tim')
                ->cascadeOnDelete();

            $table->foreignId('pegawai_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique([
                'tim_id',
                'pegawai_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_anggota_tim');
    }
};