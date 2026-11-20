<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_kegiatan', function (Blueprint $table) {
            // Jenis dinas kegiatan. Nullable agar data lama tetap valid.
            $table->enum('status_dinas', [
                'dalam_dinas',
                'luar_dinas',
                'non_dinas'
            ])->nullable()->after('status');

            $table->index('status_dinas');
        });
    }

    public function down(): void
    {
        Schema::table('tb_kegiatan', function (Blueprint $table) {
            $table->dropIndex(['status_dinas']);
            $table->dropColumn('status_dinas');
        });
    }
};
