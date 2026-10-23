<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RuanganController extends Controller
{
    /**
     * Menampilkan daftar ruangan.
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('tb_ruangan')
                ->select(
                    'id',
                    'nama_ruangan',
                    'lokasi',
                    'kapasitas',
                    'keterangan',
                    'status',
                    'created_at',
                    'updated_at'
                );

            // Pencarian berdasarkan nama ruangan atau lokasi
            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_ruangan', 'like', '%' . $search . '%')
                        ->orWhere('lokasi', 'like', '%' . $search . '%');
                });
            }

            // Filter status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $ruangan = $query
                ->orderBy('nama_ruangan')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Data ruangan berhasil diambil.',
                'data' => $ruangan
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menampilkan detail ruangan.
     */
    public function show($id)
    {
        try {
            $ruangan = DB::table('tb_ruangan')
                ->where('id', $id)
                ->first();

            if (!$ruangan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan tidak ditemukan.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail ruangan berhasil diambil.',
                'data' => $ruangan
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menambahkan ruangan baru.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_ruangan' => 'required|string|max:255',
            'lokasi' => 'nullable|string|max:255',
            'kapasitas' => 'nullable|integer|min:1',
            'keterangan' => 'nullable|string',
            'status' => 'nullable|in:aktif,nonaktif',
        ], [
            'nama_ruangan.required' => 'Nama ruangan wajib diisi.',
            'nama_ruangan.string' => 'Nama ruangan harus berupa teks.',
            'nama_ruangan.max' => 'Nama ruangan maksimal 255 karakter.',

            'lokasi.string' => 'Lokasi harus berupa teks.',
            'lokasi.max' => 'Lokasi maksimal 255 karakter.',

            'kapasitas.integer' => 'Kapasitas harus berupa angka.',
            'kapasitas.min' => 'Kapasitas minimal 1.',

            'keterangan.string' => 'Keterangan harus berupa teks.',

            'status.in' => 'Status ruangan hanya boleh aktif atau nonaktif.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $namaRuangan = trim($request->nama_ruangan);

            // Cek nama ruangan yang sama
            $sudahAda = DB::table('tb_ruangan')
                ->whereRaw('LOWER(nama_ruangan) = ?', [strtolower($namaRuangan)])
                ->exists();

            if ($sudahAda) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama ruangan sudah digunakan.'
                ], 422);
            }

            $id = DB::table('tb_ruangan')->insertGetId([
                'nama_ruangan' => $namaRuangan,
                'lokasi' => $request->lokasi,
                'kapasitas' => $request->kapasitas,
                'keterangan' => $request->keterangan,
                'status' => $request->status ?? 'aktif',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ruangan = DB::table('tb_ruangan')
                ->where('id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Ruangan berhasil ditambahkan.',
                'data' => $ruangan
            ], 201);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mengubah data ruangan.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nama_ruangan' => 'required|string|max:255',
            'lokasi' => 'nullable|string|max:255',
            'kapasitas' => 'nullable|integer|min:1',
            'keterangan' => 'nullable|string',
            'status' => 'required|in:aktif,nonaktif',
        ], [
            'nama_ruangan.required' => 'Nama ruangan wajib diisi.',
            'nama_ruangan.string' => 'Nama ruangan harus berupa teks.',
            'nama_ruangan.max' => 'Nama ruangan maksimal 255 karakter.',

            'lokasi.string' => 'Lokasi harus berupa teks.',
            'lokasi.max' => 'Lokasi maksimal 255 karakter.',

            'kapasitas.integer' => 'Kapasitas harus berupa angka.',
            'kapasitas.min' => 'Kapasitas minimal 1.',

            'keterangan.string' => 'Keterangan harus berupa teks.',

            'status.required' => 'Status ruangan wajib diisi.',
            'status.in' => 'Status ruangan hanya boleh aktif atau nonaktif.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $ruangan = DB::table('tb_ruangan')
                ->where('id', $id)
                ->first();

            if (!$ruangan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan tidak ditemukan.'
                ], 404);
            }

            $namaRuangan = trim($request->nama_ruangan);

            $sudahAda = DB::table('tb_ruangan')
                ->whereRaw('LOWER(nama_ruangan) = ?', [strtolower($namaRuangan)])
                ->where('id', '!=', $id)
                ->exists();

            if ($sudahAda) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama ruangan sudah digunakan.'
                ], 422);
            }

            DB::table('tb_ruangan')
                ->where('id', $id)
                ->update([
                    'nama_ruangan' => $namaRuangan,
                    'lokasi' => $request->lokasi,
                    'kapasitas' => $request->kapasitas,
                    'keterangan' => $request->keterangan,
                    'status' => $request->status,
                    'updated_at' => now(),
                ]);

            $data = DB::table('tb_ruangan')
                ->where('id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Ruangan berhasil diperbarui.',
                'data' => $data
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menghapus ruangan.
     */
    public function destroy($id)
    {
        try {
            $ruangan = DB::table('tb_ruangan')
                ->where('id', $id)
                ->first();

            if (!$ruangan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan tidak ditemukan.'
                ], 404);
            }

            $memilikiPemesanan = DB::table('tb_pemesanan_ruangan')
                ->where('ruangan_id', $id)
                ->exists();

            if ($memilikiPemesanan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan tidak dapat dihapus karena sudah memiliki riwayat pemesanan. Silakan ubah status menjadi nonaktif.'
                ], 422);
            }

            DB::table('tb_ruangan')
                ->where('id', $id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ruangan berhasil dihapus.'
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}