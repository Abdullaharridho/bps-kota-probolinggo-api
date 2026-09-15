<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricCredential;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Login menggunakan username dan password.
     */
    public function login(Request $request)
{
    $credentials = $request->validate([
        'username' => ['required', 'string'],
        'password' => ['required', 'string'],
    ]);

    $user = User::where('username', $credentials['username'])->first();

    if (!$user || !Hash::check($credentials['password'], $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Username atau password salah.',
        ], 401);
    }

    $user->tokens()->delete();

    $token = $user->createToken('android-session')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Login berhasil.',
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role,
        ],
    ]);
}

    /**
     * Mengambil data user yang sedang login.
     */
    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Mengubah profil user yang sedang login.
     *
     * Yang dapat diubah:
     * - name
     * - username
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
        ]);

        $usernameChanged = $user->username !== $validated['username'];

        $user->name = $validated['name'];
        $user->username = $validated['username'];
        $user->save();

        /*
         * Jika username berubah, token lama kita hapus.
         *
         * Catatan:
         * Request Android yang sedang berjalan tetap bisa mendapatkan
         * response ini karena perubahan token dilakukan setelah proses update.
         *
         * Setelah itu Android harus meminta login ulang.
         */
        if ($usernameChanged) {
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Profil berhasil diperbarui. Silakan login kembali karena username telah berubah.',
                'requires_login' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'requires_login' => false,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Mengubah password user yang sedang login.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        /*
         * Pastikan password lama benar.
         */
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password saat ini salah.',
            ], 422);
        }

        /*
         * Jangan izinkan password baru sama dengan password lama.
         */
        if (Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password baru harus berbeda dari password saat ini.',
            ], 422);
        }

        /*
         * Simpan password baru dalam bentuk hash.
         */
        $user->password = Hash::make($validated['password']);
        $user->save();

        /*
         * Semua token lama dihapus.
         *
         * Ini penting karena password telah berubah.
         * Android harus login kembali menggunakan password baru.
         */
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diubah. Silakan login kembali.',
            'requires_login' => true,
        ]);
    }

    /**
     * Logout.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    }
    public function biometricLogin(Request $request)
{
    $validated = $request->validate([
        'credential_id' => ['required', 'string', 'max:255'],
    ]);

    $credential = BiometricCredential::with('user')
        ->where('credential_id', $validated['credential_id'])
        ->where('is_active', true)
        ->first();

    if (!$credential || !$credential->user) {
        return response()->json([
            'success' => false,
            'message' => 'Credential biometrik tidak valid atau sudah tidak aktif.',
        ], 401);
    }

    $user = $credential->user;

    /*
     * Hapus token session lama milik user.
     *
     * Credential biometrik sendiri TIDAK disimpan
     * sebagai Sanctum token.
     */
    $user->tokens()->delete();

    $token = $user->createToken('android-session')->plainTextToken;

    $credential->update([
        'last_used_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Login biometrik berhasil.',
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role,
        ],
    ]);
}
/**
 * Mendaftarkan credential biometrik milik user yang sedang login.
 */
public function biometricRegister(Request $request)
{
    $validated = $request->validate([
        'credential_id' => [
            'required',
            'string',
            'max:255',
            'unique:biometric_credentials,credential_id',
        ],

        'device_name' => [
            'nullable',
            'string',
            'max:255',
        ],
    ]);

    $user = $request->user();

    /*
     * Satu user boleh memiliki lebih dari satu
     * credential biometrik / perangkat.
     *
     * Credential lama tidak dihapus.
     */
    $credential = BiometricCredential::create([
        'user_id' => $user->id,
        'credential_id' => $validated['credential_id'],
        'device_name' => $validated['device_name'] ?? null,
        'is_active' => true,
        'last_used_at' => null,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Credential biometrik berhasil didaftarkan.',
        'credential' => [
            'id' => $credential->id,
            'credential_id' => $credential->credential_id,
            'device_name' => $credential->device_name,
            'is_active' => $credential->is_active,
        ],
    ]);
}
}