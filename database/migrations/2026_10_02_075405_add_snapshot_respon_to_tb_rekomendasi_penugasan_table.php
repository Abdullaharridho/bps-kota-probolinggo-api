<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_rekomendasi_penugasan', function (Blueprint $table) {
            $table->dateTime('waktu_respon_sebelumnya')
                ->nullable();

            $table->text('catatan_respon_sebelumnya')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tb_rekomendasi_penugasan', function (Blueprint $table) {
            $table->dropColumn([
                'waktu_respon_sebelumnya',
                'catatan_respon_sebelumnya',
            ]);
        });
    }
};