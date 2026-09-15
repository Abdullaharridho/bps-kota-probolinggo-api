<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->string('anonymous_id', 100)
                ->index();

            $table->string('event', 50)
                ->index();

            $table->string('screen', 100)
                ->nullable()
                ->index();

            $table->string('device_model', 255)
                ->nullable();

            $table->unsignedSmallInteger('android_version')
                ->nullable();

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            /*
             * Index gabungan untuk kebutuhan statistik berdasarkan
             * anonymous_id dan waktu akses.
             */
            $table->index([
                'anonymous_id',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};