<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LaporanKegiatanController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('tb_laporan_kegiatan')
            ->join('tb_penugasan', 'tb_laporan_kegiatan.penugasan_id', '=', 'tb_penugasan.id')
            ->join('users', 'tb_laporan_kegiatan.dilaporkan_oleh', '=', 'users.id')
            ->select(
                'tb_laporan_kegiatan.*',
                'users.name as pelapor'
            )
            ->orderBy('tb_laporan_kegiatan.created_at', 'desc');

        return response()->json([
            'success' => true,
            'message' => 'Data laporan berhasil diambil.',
            'data' => $query->get()
        ]);
    }

    public function show(string $id)
    {
        $laporan = DB::table('tb_laporan_kegiatan')
            ->join('users', 'tb_laporan_kegiatan.dilaporkan_oleh', '=', 'users.id')
            ->select('tb_laporan_kegiatan.*', 'users.name as pelapor')
            ->where('tb_laporan_kegiatan.id', $id)
            ->first();

        if (!$laporan) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        $laporan->dokumen = DB::table('tb_dokumen_kegiatan')
            ->where('laporan_kegiatan_id', $id)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $laporan
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'penugasan_id' => 'required|exists:tb_penugasan,id',
            'ringkasan' => 'required|string',
            'hasil_kegiatan' => 'required|string',
            'catatan' => 'nullable|string',

            // Batasi maksimal 5 file dalam 1 kali upload
            'dokumen' => 'required|array|max:5',

            // Batasi ekstensi (Hanya Gambar, PDF, Word) dan ukuran max 5MB (5120 KB)
            'dokumen.*.file' => 'required|file|mimes:jpeg,png,jpg,pdf,doc,docx|max:5120',

            'dokumen.*.jenis_dokumen' => ['required', Rule::in(['materi', 'dokumentasi', 'notulen', 'lainnya'])]
        ]);

        DB::beginTransaction();
        try {
            $laporanId = DB::table('tb_laporan_kegiatan')->insertGetId([
                'penugasan_id' => $validated['penugasan_id'],
                'ringkasan' => $validated['ringkasan'],
                'hasil_kegiatan' => $validated['hasil_kegiatan'],
                'catatan' => $validated['catatan'] ?? null,
                'dilaporkan_oleh' => $request->user()->id,
                'tanggal_laporan' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            foreach ($request->file('dokumen') as $index => $docData) {
                $file = $docData['file'];
                $jenis = $request->dokumen[$index]['jenis_dokumen'];

                $path = $file->store('dokumen_kegiatan', 'public');

                DB::table('tb_dokumen_kegiatan')->insert([
                    'laporan_kegiatan_id' => $laporanId,
                    'diunggah_oleh' => $request->user()->id,
                    'jenis_dokumen' => $jenis,
                    'nama_file' => $file->getClientOriginalName(),
                    'path_file' => $path,
                    'mime_type' => $file->getMimeType(),
                    'ukuran_file' => $file->getSize(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            DB::table('tb_penugasan')
                ->where('id', $validated['penugasan_id'])
                ->update([
                    'status' => 'selesai',
                    'updated_at' => now()
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Laporan berhasil disubmit dan status penugasan otomatis diselesaikan.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
            ], 500);
        }
    }
}
