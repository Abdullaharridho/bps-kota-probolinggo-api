<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TimController extends Controller
{
    /**
     * Daftar tim beserta nama ketua.
     */
    public function index(Request $request)
    {
        $query = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select(
                'tb_tim.*',
                'users.name as nama_ketua'
            )
            ->orderBy('tb_tim.created_at', 'desc');

        if ($request->filled('search')) {
            $query->where(
                'tb_tim.nama_tim',
                'like',
                '%' . $request->search . '%'
            );
        }

        if ($request->filled('status')) {
            $query->where('tb_tim.status', $request->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data tim berhasil diambil.',
            'data' => $query->get()
        ]);
    }

    /**
     * Detail tim beserta daftar anggotanya.
     */
    public function show($id)
    {
        $tim = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select(
                'tb_tim.*',
                'users.name as nama_ketua'
            )
            ->where('tb_tim.id', $id)
            ->first();

        if (!$tim) {
            return response()->json([
                'success' => false,
                'message' => 'Data tim tidak ditemukan.'
            ], 404);
        }

        $tim->anggota = DB::table('tb_anggota_tim')
            ->join(
                'users',
                'tb_anggota_tim.pegawai_id',
                '=',
                'users.id'
            )
            ->select(
                'tb_anggota_tim.id as id_keanggotaan',
                'users.id as pegawai_id',
                'users.name',
                'users.role'
            )
            ->where('tb_anggota_tim.tim_id', $id)
            ->orderBy('users.name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tim
        ]);
    }

    /**
     * Membuat tim baru.
     *
     * Ketua hanya boleh berasal dari role selain
     * operator dan super_admin.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_tim' => [
                'required',
                'string',
                'max:255'
            ],

            'ketua_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->whereNotIn('role', [
                        'operator',
                        'super_admin'
                    ]),
            ],

            'keterangan' => [
                'nullable',
                'string'
            ],

            'status' => [
                'nullable',
                Rule::in(['aktif', 'nonaktif'])
            ],
        ]);

        $validated['status'] = $validated['status'] ?? 'aktif';
        $validated['created_at'] = now();
        $validated['updated_at'] = now();

        $id = DB::table('tb_tim')->insertGetId($validated);

        $tim = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select(
                'tb_tim.*',
                'users.name as nama_ketua'
            )
            ->where('tb_tim.id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Tim berhasil dibuat.',
            'data' => $tim
        ], 201);
    }

    /**
     * Mengubah data tim.
     *
     * Semua field bersifat opsional, tetapi jika
     * dikirim harus memenuhi aturan validasi.
     */
    public function update(Request $request, $id)
    {
        $tim = DB::table('tb_tim')
            ->where('id', $id)
            ->first();

        if (!$tim) {
            return response()->json([
                'success' => false,
                'message' => 'Data tim tidak ditemukan.'
            ], 404);
        }

        $validated = $request->validate([
            'nama_tim' => [
                'sometimes',
                'required',
                'string',
                'max:255'
            ],

            'ketua_id' => [
                'sometimes',
                'required',
                Rule::exists('users', 'id')
                    ->whereNotIn('role', [
                        'operator',
                        'super_admin'
                    ]),
            ],

            'keterangan' => [
                'sometimes',
                'nullable',
                'string'
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(['aktif', 'nonaktif'])
            ],
        ]);

        if (empty($validated)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada data yang diperbarui.'
            ], 422);
        }

        $validated['updated_at'] = now();

        DB::table('tb_tim')
            ->where('id', $id)
            ->update($validated);

        $timTerbaru = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select(
                'tb_tim.*',
                'users.name as nama_ketua'
            )
            ->where('tb_tim.id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Data tim berhasil diperbarui.',
            'data' => $timTerbaru
        ]);
    }

    /**
     * Menghapus tim.
     *
     * Anggota tim ikut terhapus melalui
     * cascadeOnDelete pada foreign key tim_id.
     */
    public function destroy($id)
    {
        $deleted = DB::table('tb_tim')
            ->where('id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Data tim tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tim berhasil dihapus.'
        ]);
    }

    /**
     * Menambahkan anggota ke dalam tim.
     *
     * Anggota tidak boleh memiliki role operator
     * atau super_admin, dan ketua tim tidak perlu
     * dicatat kembali sebagai anggota.
     */
    public function tambahAnggota(Request $request, $id)
    {
        $tim = DB::table('tb_tim')
            ->where('id', $id)
            ->first();

        if (!$tim) {
            return response()->json([
                'success' => false,
                'message' => 'Tim tidak ditemukan.'
            ], 404);
        }

        $validated = $request->validate([
            'pegawai_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->whereNotIn('role', [
                        'operator',
                        'super_admin'
                    ]),
            ],
        ]);

        $pegawaiId = (int) $validated['pegawai_id'];

        // Ketua tidak perlu dimasukkan kembali sebagai anggota.
        if ((int) $tim->ketua_id === $pegawaiId) {
            return response()->json([
                'success' => false,
                'message' => 'Ketua tim tidak perlu ditambahkan sebagai anggota.'
            ], 422);
        }

        // Cek apakah pegawai sudah menjadi anggota tim ini.
        $exists = DB::table('tb_anggota_tim')
            ->where('tim_id', $id)
            ->where('pegawai_id', $pegawaiId)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Pegawai tersebut sudah menjadi anggota di tim ini.'
            ], 422);
        }

        DB::transaction(function () use ($id, $pegawaiId) {
            DB::table('tb_anggota_tim')->insert([
                'tim_id' => $id,
                'pegawai_id' => $pegawaiId,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Anggota berhasil ditambahkan ke tim.'
        ], 201);
    }

    /**
     * Menghapus anggota dari tim.
     */
    public function hapusAnggota($id, $pegawai_id)
    {
        // Pastikan tim memang tersedia.
        $timExists = DB::table('tb_tim')
            ->where('id', $id)
            ->exists();

        if (!$timExists) {
            return response()->json([
                'success' => false,
                'message' => 'Tim tidak ditemukan.'
            ], 404);
        }

        $deleted = DB::table('tb_anggota_tim')
            ->where('tim_id', $id)
            ->where('pegawai_id', $pegawai_id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan di tim ini.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Anggota berhasil dihapus dari tim.'
        ]);
    }
}