<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Kolom yang boleh diisi menggunakan mass assignment.
     */
    protected $fillable = [
        'name',
        'username',
        'password',
        'jenis_kelamin',
        'id_jabatan',
        'role',
        'nip',
        'no_hp',
        'golongan',
        'status',
    ];

    /**
     * Relasi kredensial biometrik.
     */
    public function biometricCredentials()
    {
        return $this->hasMany(BiometricCredential::class);
    }

    /**
     * Kolom yang disembunyikan ketika model diubah menjadi JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Casting attribute.
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
