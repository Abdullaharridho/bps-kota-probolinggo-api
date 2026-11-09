<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends Controller
{
    /**
     * Menampilkan semua user.
     */
    public function index()
    {
        $users = User::leftJoin(
            'jabatan',
            'users.id_jabatan',
            '=',
            'jabatan.id'
        )
            ->select(
                'users.id',
                'users.name',
                'users.username',
                'users.jenis_kelamin',
                'users.id_jabatan',
                'jabatan.nama_jabatan',
                'users.role',
                'users.nip',
                'users.no_hp',
                'users.golongan',
                'users.status',
                'users.created_at',
                'users.updated_at'
            )
            ->orderBy('users.created_at', 'desc')
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
        $data = User::leftJoin(
            'jabatan',
            'users.id_jabatan',
            '=',
            'jabatan.id'
        )
            ->where('users.id', $user->id)
            ->select(
                'users.id',
                'users.name',
                'users.username',
                'users.jenis_kelamin',
                'users.id_jabatan',
                'jabatan.nama_jabatan',
                'users.role',
                'users.nip',
                'users.no_hp',
                'users.golongan',
                'users.status',
                'users.created_at',
                'users.updated_at'
            )
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Detail user berhasil diambil.',
            'data' => $data,
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

            'jenis_kelamin' => [
                'nullable',
                Rule::in([
                    'laki-laki',
                    'perempuan',
                ]),
            ],

            'id_jabatan' => [
                'nullable',
                'integer',
                'exists:jabatan,id',
            ],

            'role' => [
                'required',
                Rule::in([
                    'super_admin',
                    'pimpinan',
                    'operator',
                    'ketua_tim',
                    'pegawai',
                    'mitra',
                ]),
            ],

            'nip' => [
                'nullable',
                'string',
                'max:30',
                'unique:users,nip',
            ],

            'no_hp' => [
                'nullable',
                'string',
                'max:20',
            ],

            'golongan' => [
                'nullable',
                Rule::in([
                    'I/a', 'I/b', 'I/c', 'I/d',
                    'II/a', 'II/b', 'II/c', 'II/d',
                    'III/a', 'III/b', 'III/c', 'III/d',
                    'IV/a', 'IV/b', 'IV/c', 'IV/d', 'IV/e',
                ]),
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in([
                    'aktif',
                    'nonaktif',
                ]),
            ],
        ]);

        // Password otomatis di-hash oleh casts pada model User.
        $user = User::create($validated);

        // Ambil nama jabatan tanpa membuat model baru.
        $namaJabatan = DB::table('jabatan')
            ->where('id', $user->id_jabatan)
            ->value('nama_jabatan');

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dibuat.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'jenis_kelamin' => $user->jenis_kelamin,
                'id_jabatan' => $user->id_jabatan,
                'nama_jabatan' => $namaJabatan,
                'role' => $user->role,
                'nip' => $user->nip,
                'no_hp' => $user->no_hp,
                'golongan' => $user->golongan,
                'status' => $user->status,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
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

            'jenis_kelamin' => [
                'nullable',
                Rule::in([
                    'laki-laki',
                    'perempuan',
                ]),
            ],

            'id_jabatan' => [
                'nullable',
                'integer',
                'exists:jabatan,id',
            ],

            'role' => [
                'required',
                Rule::in([
                    'super_admin',
                    'pimpinan',
                    'operator',
                    'ketua_tim',
                    'pegawai',
                    'mitra',
                ]),
            ],

            'nip' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('users', 'nip')
                    ->ignore($user->id),
            ],

            'no_hp' => [
                'nullable',
                'string',
                'max:20',
            ],

            'golongan' => [
                'nullable',
                Rule::in([
                    'I/a', 'I/b', 'I/c', 'I/d',
                    'II/a', 'II/b', 'II/c', 'II/d',
                    'III/a', 'III/b', 'III/c', 'III/d',
                    'IV/a', 'IV/b', 'IV/c', 'IV/d', 'IV/e',
                ]),
            ],

            'status' => [
                'required',
                Rule::in([
                    'aktif',
                    'nonaktif',
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
        $user->jenis_kelamin = $validated['jenis_kelamin'] ?? null;
        $user->id_jabatan = $validated['id_jabatan'] ?? null;
        $user->role = $validated['role'];
        $user->nip = $validated['nip'] ?? null;
        $user->no_hp = $validated['no_hp'] ?? null;
        $user->golongan = $validated['golongan'] ?? null;
        $user->status = $validated['status'];

        // Password hanya diperbarui jika diisi.
        // Hash otomatis ditangani oleh casts pada model User.
        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        // Ambil nama jabatan terbaru.
        $namaJabatan = DB::table('jabatan')
            ->where('id', $user->id_jabatan)
            ->value('nama_jabatan');

        return response()->json([
            'success' => true,
            'message' => 'User berhasil diperbarui.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'jenis_kelamin' => $user->jenis_kelamin,
                'id_jabatan' => $user->id_jabatan,
                'nama_jabatan' => $namaJabatan,
                'role' => $user->role,
                'nip' => $user->nip,
                'no_hp' => $user->no_hp,
                'golongan' => $user->golongan,
                'status' => $user->status,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
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
         * akun yang sedang digunakan.
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
    public function getJabatan()
{
    $jabatan = DB::table('jabatan')
        ->where('status', 'aktif')
        ->orderBy('nama_jabatan', 'asc')
        ->select('id', 'nama_jabatan')
        ->get();

    return response()->json([
        'success' => true,
        'message' => 'Data jabatan berhasil diambil.',
        'data' => $jabatan
    ], 200);
}
}
