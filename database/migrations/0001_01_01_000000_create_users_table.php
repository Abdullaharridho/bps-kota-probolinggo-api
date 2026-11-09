
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('username')->unique();
            $table->string('password');

            $table->enum('jenis_kelamin', [
                'laki-laki',
                'perempuan'
            ])->nullable();

            // Relasi ke tabel jabatans
            $table->foreignId('id_jabatan')
                ->nullable()
                ->constrained('jabatans')
                ->nullOnDelete();

            $table->enum('role', [
                'super_admin',
                'pimpinan',
                'operator',
                'ketua_tim',
                'pegawai',
                'mitra'
            ])->default('pegawai');

            $table->string('nip', 30)->nullable()->unique();
            $table->string('no_hp', 20)->nullable();

            // Golongan PNS
            $table->enum('golongan', [
                'I/a',
                'I/b',
                'I/c',
                'I/d',
                'II/a',
                'II/b',
                'II/c',
                'II/d',
                'III/a',
                'III/b',
                'III/c',
                'III/d',
                'IV/a',
                'IV/b',
                'IV/c',
                'IV/d',
                'IV/e'
            ])->nullable();

            // Status akun tetap dipertahankan
            $table->enum('status', [
                'aktif',
                'nonaktif'
            ])->default('aktif');

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
