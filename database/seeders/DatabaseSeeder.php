<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Membuat 1 akun Super Admin untuk testing
        User::create([
            'name' => 'Super Admin BPS',
            'username' => 'admin_bps',
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'no_hp' => '081234567890',
            'status' => 'aktif'
        ]);
    }
}
