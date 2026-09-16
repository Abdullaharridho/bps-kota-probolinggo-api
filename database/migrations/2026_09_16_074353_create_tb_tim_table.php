<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_tim', function (Blueprint $table) {
            $table->id();

            $table->string('nama_tim');
            
            $table->foreignId('ketua_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('keterangan')->nullable();

            $table->enum('status', [
                'aktif',
                'nonaktif'
            ])->default('aktif');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tim');
    }
};