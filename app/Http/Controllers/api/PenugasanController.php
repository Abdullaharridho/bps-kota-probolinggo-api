<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PenugasanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = DB::table('tb_penugasan')
            ->join('tb_surat_masuk', 'tb_penugasan.surat_masuk_id', '=', 'tb_surat_masuk.id')
            ->join('users as pengirim', 'tb_penugasan.ditugaskan_oleh', '=', 'pengirim.id')
            ->join('users as penerima', 'tb_penugasan.ditugaskan_kepada', '=', 'penerima.id')
            ->select(
                'tb_penugasan.*',
                'tb_surat_masuk.nomor_surat',
                'tb_surat_masuk.perihal',
                'pengirim.name as nama_pengirim',
                'penerima.name as nama_penerima'
            )
            ->orderBy('tb_penugasan.created_at', 'desc');

        if (!in_array($user->role, ['super_admin', 'pimpinan'])) {
            $query->where('tb_penugasan.ditugaskan_kepada', $user->id)
                  ->orWhere('tb_penugasan.ditugaskan_oleh', $user->id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('tb_surat_masuk.perihal', 'like', "%{$search}%")
                  ->orWhere('tb_surat_masuk.nomor_surat', 'like', "%{$search}%")
                  ->orWhere('penerima.name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('tb_penugasan.status', $request->status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    public function show(string $id)
    {
        $penugasan = DB::table('tb_penugasan')
            ->join('tb_surat_masuk', 'tb_penugasan.surat_masuk_id', '=', 'tb_surat_masuk.id')
            ->join('users as pengirim', 'tb_penugasan.ditugaskan_oleh', '=', 'pengirim.id')
            ->join('users as penerima', 'tb_penugasan.ditugaskan_kepada', '=', 'penerima.id')
            ->select(
                'tb_penugasan.*',
                'tb_surat_masuk.nomor_surat',
                'tb_surat_masuk.perihal',
                'pengirim.name as nama_pengirim',
                'penerima.name as nama_penerima'
            )
            ->where('tb_penugasan.id', $id)
            ->first();

        if (!$penugasan) {
            return response()->json(['success' => false, 'message' => 'Data penugasan tidak ditemukan.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $penugasan
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'surat_masuk_id' => 'required|exists:tb_surat_masuk,id',
            'ditugaskan_kepada' => 'required|exists:users,id',
            'catatan_penugasan' => 'nullable|string'
        ]);

        $validated['ditugaskan_oleh'] = $request->user()->id;
        $validated['waktu_penugasan'] = now();
        $validated['status'] = 'menunggu_respon';
        $validated['created_at'] = now();
        $validated['updated_at'] = now();

        $id = DB::table('tb_penugasan')->insertGetId($validated);

        $penugasan = DB::table('tb_penugasan')->where('id', $id)->first();

        return response()->json([
            'success' => true,
            'message' => 'Penugasan berhasil dibuat.',
            'data' => $penugasan
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $penugasan = DB::table('tb_penugasan')->where('id', $id)->first();

        if (!$penugasan) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'ditugaskan_kepada' => 'sometimes|required|exists:users,id',
            'catatan_penugasan' => 'nullable|string'
        ]);

        $validated['updated_at'] = now();

        DB::table('tb_penugasan')->where('id', $id)->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Data penugasan berhasil diperbarui.',
            'data' => DB::table('tb_penugasan')->where('id', $id)->first()
        ]);
    }

    public function responPenugasan(Request $request, $id)
    {
        $user = $request->user();
        $penugasan = DB::table('tb_penugasan')->where('id', $id)->first();

        if (!$penugasan) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        if ($penugasan->ditugaskan_kepada !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Anda tidak berhak merespon penugasan ini.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['diterima', 'ditolak'])],
            'catatan_respon' => 'nullable|string'
        ]);

        $validated['waktu_respon'] = now();
        $validated['updated_at'] = now();

        DB::table('tb_penugasan')->where('id', $id)->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Respon penugasan berhasil disimpan.',
            'data' => DB::table('tb_penugasan')->where('id', $id)->first()
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $penugasan = DB::table('tb_penugasan')->where('id', $id)->first();

        if (!$penugasan) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        DB::table('tb_penugasan')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Penugasan berhasil dihapus.'
        ]);
    }
}
