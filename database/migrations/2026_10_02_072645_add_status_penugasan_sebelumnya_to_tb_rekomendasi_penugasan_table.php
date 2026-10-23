<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_rekomendasi_penugasan', function (Blueprint $table) {
            $table->string('status_penugasan_sebelumnya', 50)
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tb_rekomendasi_penugasan', function (Blueprint $table) {
            $table->dropColumn('status_penugasan_sebelumnya');
        });
    }
};