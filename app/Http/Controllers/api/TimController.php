<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TimController extends Controller
{
    // Daftar tim beserta nama ketua
    public function index(Request $request)
    {
        $query = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select('tb_tim.*', 'users.name as nama_ketua')
            ->orderBy('tb_tim.created_at', 'desc');

        if ($request->filled('search')) {
            $query->where('tb_tim.nama_tim', 'like', "%{$request->search}%");
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

    // Detail tim beserta list anggotanya
    public function show($id)
    {
        $tim = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select('tb_tim.*', 'users.name as nama_ketua')
            ->where('tb_tim.id', $id)
            ->first();

        if (!$tim) {
            return response()->json(['success' => false, 'message' => 'Data tim tidak ditemukan.'], 404);
        }

        $tim->anggota = DB::table('tb_anggota_tim')
            ->join('users', 'tb_anggota_tim.pegawai_id', '=', 'users.id')
            ->select('tb_anggota_tim.id as id_keanggotaan', 'users.id as pegawai_id', 'users.name', 'users.role')
            ->where('tb_anggota_tim.tim_id', $id)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tim
        ]);
    }

    // Buat tim baru
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_tim' => 'required|string|max:255',
            'ketua_id' => 'required|exists:users,id',
            'keterangan' => 'nullable|string',
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])]
        ]);

        $validated['created_at'] = now();
        $validated['updated_at'] = now();
        $validated['status'] = $validated['status'] ?? 'aktif';

        $id = DB::table('tb_tim')->insertGetId($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tim berhasil dibuat.',
            'data' => DB::table('tb_tim')->where('id', $id)->first()
        ], 201);
    }

    // Ubah data tim
    public function update(Request $request, $id)
    {
        $tim = DB::table('tb_tim')->where('id', $id)->first();
        if (!$tim) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);

        $validated = $request->validate([
            'nama_tim' => 'sometimes|required|string|max:255',
            'ketua_id' => 'sometimes|required|exists:users,id',
            'keterangan' => 'nullable|string',
            'status' => ['sometimes', Rule::in(['aktif', 'nonaktif'])]
        ]);

        $validated['updated_at'] = now();

        DB::table('tb_tim')->where('id', $id)->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Data tim berhasil diperbarui.',
            'data' => DB::table('tb_tim')->where('id', $id)->first()
        ]);
    }

    // Hapus tim (otomatis hapus anggota karena cascadeOnDelete di database)
    public function destroy($id)
    {
        $deleted = DB::table('tb_tim')->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tim berhasil dihapus.'
        ]);
    }

    // Tambah anggota ke dalam tim
    public function tambahAnggota(Request $request, $id)
    {
        $tim = DB::table('tb_tim')->where('id', $id)->first();
        if (!$tim) return response()->json(['success' => false, 'message' => 'Tim tidak ditemukan.'], 404);

        $request->validate([
            'pegawai_id' => 'required|exists:users,id'
        ]);

        // Cek apakah pegawai sudah ada di tim ini
        $exists = DB::table('tb_anggota_tim')
            ->where('tim_id', $id)
            ->where('pegawai_id', $request->pegawai_id)
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Pegawai tersebut sudah menjadi anggota di tim ini.'], 422);
        }

        DB::table('tb_anggota_tim')->insert([
            'tim_id' => $id,
            'pegawai_id' => $request->pegawai_id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Anggota berhasil ditambahkan ke tim.'
        ], 201);
    }

    // Hapus anggota dari tim
    public function hapusAnggota($id, $pegawai_id)
    {
        $deleted = DB::table('tb_anggota_tim')
            ->where('tim_id', $id)
            ->where('pegawai_id', $pegawai_id)
            ->delete();

        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'Anggota tidak ditemukan di tim ini.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Anggota berhasil dihapus dari tim.'
        ]);
    }
}
