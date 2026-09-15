<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SuperAdminController extends Controller
{
    /**
     * Menampilkan semua user.
     */
    public function index()
    {
        $users = User::select(
            'id',
            'name',
            'username',
            'role',
            'created_at',
            'updated_at'
        )
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data user berhasil diambil.',
            'data' => $users,
        ]);
    }

    /**
     * Menampilkan detail satu user.
     */
    public function show(User $user)
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail user berhasil diambil.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    /**
     * Membuat user baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'username' => [
                'required',
                'string',
                'max:255',
                'unique:users,username',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
            ],

            'role' => [
                'required',
                Rule::in([
                    'pimpinan',
                    'super_admin',
                    'user',
                ]),
            ],
        ]);

        $user = User::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dibuat.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
            ],
        ], 201);
    }

    /**
     * Mengubah data user.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')
                    ->ignore($user->id),
            ],

            'role' => [
                'required',
                Rule::in([
                    'pimpinan',
                    'super_admin',
                    'user',
                ]),
            ],

            'password' => [
                'nullable',
                'string',
                'min:6',
            ],
        ]);

        $user->name = $validated['name'];
        $user->username = $validated['username'];
        $user->role = $validated['role'];

        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User berhasil diperbarui.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Menghapus user.
     */
    public function destroy(Request $request, User $user)
    {
        /*
         * Super Admin tidak boleh menghapus
         * akun Super Admin yang sedang login.
         */
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Akun yang sedang digunakan tidak dapat dihapus.',
            ], 422);
        }

        /*
         * Hapus semua token milik user sebelum
         * menghapus akun.
         */
        $user->tokens()->delete();

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dihapus.',
        ]);
    }
}