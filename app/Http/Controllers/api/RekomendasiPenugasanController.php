<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RekomendasiPenugasanController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('tb_rekomendasi_penugasan')
            ->join('tb_penugasan', 'tb_rekomendasi_penugasan.penugasan_id', '=', 'tb_penugasan.id')
            ->join('users as asal', 'tb_rekomendasi_penugasan.pegawai_asal_id', '=', 'asal.id')
            ->join('users as rek', 'tb_rekomendasi_penugasan.pegawai_rekomendasi_id', '=', 'rek.id')
            ->select(
                'tb_rekomendasi_penugasan.*',
                'tb_penugasan.catatan_penugasan',
                'asal.name as nama_pegawai_asal',
                'rek.name as nama_pegawai_rekomendasi'
            )
            ->orderBy('tb_rekomendasi_penugasan.created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('tb_rekomendasi_penugasan.status', $request->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data rekomendasi penugasan berhasil diambil.',
            'data' => $query->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'penugasan_id' => 'required|exists:tb_penugasan,id',
            'pegawai_rekomendasi_id' => 'required|exists:users,id',
            'alasan' => 'required|string'
        ]);

        $validated['pegawai_asal_id'] = $request->user()->id;
        $validated['status'] = 'menunggu_review';
        $validated['created_at'] = now();
        $validated['updated_at'] = now();

        DB::beginTransaction();
        try {
            $id = DB::table('tb_rekomendasi_penugasan')->insertGetId($validated);
            DB::table('tb_penugasan')
                ->where('id', $validated['penugasan_id'])
                ->update([
                    'status' => 'direkomendasikan',
                    'updated_at' => now()
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan rekomendasi penugasan berhasil dikirim.',
                'data' => DB::table('tb_rekomendasi_penugasan')->where('id', $id)->first()
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem.'], 500);
        }
    }

    public function proses(Request $request, $id)
    {
        $rekomendasi = DB::table('tb_rekomendasi_penugasan')->where('id', $id)->first();

        if (!$rekomendasi) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['diterima', 'ditolak'])]
        ]);

        DB::beginTransaction();
        try {
            DB::table('tb_rekomendasi_penugasan')->where('id', $id)->update([
                'status' => $validated['status'],
                'diproses_oleh' => $request->user()->id,
                'diproses_pada' => now(),
                'updated_at' => now()
            ]);

            if ($validated['status'] === 'diterima') {
                DB::table('tb_penugasan')
                    ->where('id', $rekomendasi->penugasan_id)
                    ->update([
                        'ditugaskan_kepada' => $rekomendasi->pegawai_rekomendasi_id,
                        'status' => 'menunggu_respon',
                        'updated_at' => now()
                    ]);
            } else {
                DB::table('tb_penugasan')
                    ->where('id', $rekomendasi->penugasan_id)
                    ->update([
                        'status' => 'diterima',
                        'updated_at' => now()
                    ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rekomendasi penugasan berhasil diproses.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem.'], 500);
        }
    }
}
