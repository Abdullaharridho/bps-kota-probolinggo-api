<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_kecamatan', function (Blueprint $table) {
            $table->id();
            $table->string('kode_kecamatan', 30)->unique();
            $table->string('nama_kecamatan', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_kecamatan');
    }
};