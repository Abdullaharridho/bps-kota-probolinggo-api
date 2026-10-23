<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PemesananRuanganController extends Controller
{
    /**
     * Menampilkan daftar pemesanan ruangan.
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('tb_pemesanan_ruangan as p')
                ->join('tb_ruangan as r', 'r.id', '=', 'p.ruangan_id')
                ->select(
                    'p.id',
                    'p.ruangan_id',
                    'r.nama_ruangan',
                    'r.lokasi',
                    'p.nama_pemesan',
                    'p.instansi',
                    'p.judul_kegiatan',
                    'p.tanggal',
                    'p.waktu_mulai',
                    'p.waktu_selesai',
                    'p.status',
                    'p.catatan',
                    'p.created_at',
                    'p.updated_at'
                );

            // Filter tanggal
            if ($request->filled('tanggal')) {
                $query->whereDate('p.tanggal', $request->tanggal);
            }

            // Filter ruangan
            if ($request->filled('ruangan_id')) {
                $query->where('p.ruangan_id', $request->ruangan_id);
            }

            // Filter status
            if ($request->filled('status')) {
                $query->where('p.status', $request->status);
            }

            // Pencarian
            if ($request->filled('search')) {
                $search = trim($request->search);

                $query->where(function ($q) use ($search) {
                    $q->where('p.nama_pemesan', 'like', '%' . $search . '%')
                        ->orWhere('p.instansi', 'like', '%' . $search . '%')
                        ->orWhere('p.judul_kegiatan', 'like', '%' . $search . '%')
                        ->orWhere('r.nama_ruangan', 'like', '%' . $search . '%');
                });
            }

            $pemesanan = $query
                ->orderBy('p.tanggal')
                ->orderBy('p.waktu_mulai')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Data pemesanan ruangan berhasil diambil.',
                'data' => $pemesanan
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pemesanan ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menampilkan detail pemesanan ruangan.
     */
    public function show($id)
    {
        try {
            $pemesanan = DB::table('tb_pemesanan_ruangan as p')
                ->join('tb_ruangan as r', 'r.id', '=', 'p.ruangan_id')
                ->select(
                    'p.id',
                    'p.ruangan_id',
                    'r.nama_ruangan',
                    'r.lokasi',
                    'r.kapasitas',
                    'p.nama_pemesan',
                    'p.instansi',
                    'p.judul_kegiatan',
                    'p.tanggal',
                    'p.waktu_mulai',
                    'p.waktu_selesai',
                    'p.status',
                    'p.catatan',
                    'p.created_at',
                    'p.updated_at'
                )
                ->where('p.id', $id)
                ->first();

            if (!$pemesanan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pemesanan ruangan tidak ditemukan.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail pemesanan ruangan berhasil diambil.',
                'data' => $pemesanan
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail pemesanan ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menambahkan pemesanan ruangan.
     *
     * Status awal selalu "dipesan".
     * Perubahan status berikutnya dilakukan oleh Scheduler.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ruangan_id' => 'required|integer|exists:tb_ruangan,id',
            'nama_pemesan' => 'required|string|max:255',
            'instansi' => 'nullable|string|max:255',
            'judul_kegiatan' => 'required|string|max:255',
            'tanggal' => 'required|date',
            'waktu_mulai' => 'required|date_format:H:i',
            'waktu_selesai' => 'required|date_format:H:i|after:waktu_mulai',
            'catatan' => 'nullable|string',
        ], [
            'ruangan_id.required' => 'Ruangan wajib dipilih.',
            'ruangan_id.exists' => 'Ruangan tidak ditemukan.',

            'nama_pemesan.required' => 'Nama pemesan wajib diisi.',
            'nama_pemesan.max' => 'Nama pemesan maksimal 255 karakter.',

            'instansi.max' => 'Instansi maksimal 255 karakter.',

            'judul_kegiatan.required' => 'Judul kegiatan wajib diisi.',
            'judul_kegiatan.max' => 'Judul kegiatan maksimal 255 karakter.',

            'tanggal.required' => 'Tanggal wajib diisi.',
            'tanggal.date' => 'Format tanggal tidak valid.',

            'waktu_mulai.required' => 'Waktu mulai wajib diisi.',
            'waktu_mulai.date_format' => 'Format waktu mulai harus HH:MM.',

            'waktu_selesai.required' => 'Waktu selesai wajib diisi.',
            'waktu_selesai.date_format' => 'Format waktu selesai harus HH:MM.',
            'waktu_selesai.after' => 'Waktu selesai harus setelah waktu mulai.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            /*
             * Kunci data ruangan selama proses transaksi
             * untuk menghindari perubahan status ruangan
             * pada saat pemesanan sedang diproses.
             */
            $ruangan = DB::table('tb_ruangan')
                ->where('id', $request->ruangan_id)
                ->lockForUpdate()
                ->first();

            if (!$ruangan) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan tidak ditemukan.'
                ], 404);
            }

            /*
             * Hanya ruangan aktif yang dapat dipesan.
             */
            if ($ruangan->status !== 'aktif') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan sedang nonaktif dan tidak dapat dipesan.'
                ], 422);
            }

            /*
             * Pengecekan bentrok waktu.
             *
             * Bentrok apabila:
             *
             * waktu_mulai_baru < waktu_selesai_lama
             * DAN
             * waktu_selesai_baru > waktu_mulai_lama
             *
             * Contoh:
             *
             * 08:00 - 10:00
             * 10:00 - 12:00
             *
             * Tidak dianggap bentrok.
             *
             * Sedangkan:
             *
             * 08:00 - 10:00
             * 09:00 - 11:00
             *
             * dianggap bentrok.
             *
             * Pemesanan yang dibatalkan tidak dianggap bentrok.
             */
            $bentrok = DB::table('tb_pemesanan_ruangan')
                ->where('ruangan_id', $request->ruangan_id)
                ->whereDate('tanggal', $request->tanggal)
                ->where('status', '!=', 'dibatalkan')
                ->where('waktu_mulai', '<', $request->waktu_selesai)
                ->where('waktu_selesai', '>', $request->waktu_mulai)
                ->exists();

            if ($bentrok) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan sudah dipesan pada waktu tersebut. Silakan pilih waktu atau ruangan lain.'
                ], 422);
            }

            /*
             * Status awal selalu dipesan.
             *
             * Status:
             * dipesan    -> sebelum waktu mulai
             * digunakan  -> saat kegiatan berlangsung
             * selesai    -> setelah waktu selesai
             * dibatalkan -> aksi khusus
             */
            $sekarang = now();

            $id = DB::table('tb_pemesanan_ruangan')->insertGetId([
                'ruangan_id' => $request->ruangan_id,
                'nama_pemesan' => trim($request->nama_pemesan),
                'instansi' => $request->filled('instansi')
                    ? trim($request->instansi)
                    : null,
                'judul_kegiatan' => trim($request->judul_kegiatan),
                'tanggal' => $request->tanggal,
                'waktu_mulai' => $request->waktu_mulai,
                'waktu_selesai' => $request->waktu_selesai,
                'status' => 'dipesan',
                'catatan' => $request->filled('catatan')
                    ? trim($request->catatan)
                    : null,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);

            DB::commit();

            /*
             * Ambil kembali data lengkap setelah berhasil disimpan.
             */
            $pemesanan = DB::table('tb_pemesanan_ruangan as p')
                ->join('tb_ruangan as r', 'r.id', '=', 'p.ruangan_id')
                ->select(
                    'p.id',
                    'p.ruangan_id',
                    'r.nama_ruangan',
                    'r.lokasi',
                    'r.kapasitas',
                    'p.nama_pemesan',
                    'p.instansi',
                    'p.judul_kegiatan',
                    'p.tanggal',
                    'p.waktu_mulai',
                    'p.waktu_selesai',
                    'p.status',
                    'p.catatan',
                    'p.created_at',
                    'p.updated_at'
                )
                ->where('p.id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Pemesanan ruangan berhasil dibuat.',
                'data' => $pemesanan
            ], 201);

        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat pemesanan ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mengubah data pemesanan ruangan.
     *
     * Status tidak diubah melalui method ini.
     * Status otomatis dikelola oleh Scheduler.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'ruangan_id' => 'required|integer|exists:tb_ruangan,id',
            'nama_pemesan' => 'required|string|max:255',
            'instansi' => 'nullable|string|max:255',
            'judul_kegiatan' => 'required|string|max:255',
            'tanggal' => 'required|date',
            'waktu_mulai' => 'required|date_format:H:i',
            'waktu_selesai' => 'required|date_format:H:i|after:waktu_mulai',
            'catatan' => 'nullable|string',
        ], [
            'ruangan_id.required' => 'Ruangan wajib dipilih.',
            'ruangan_id.exists' => 'Ruangan tidak ditemukan.',

            'nama_pemesan.required' => 'Nama pemesan wajib diisi.',
            'nama_pemesan.max' => 'Nama pemesan maksimal 255 karakter.',

            'instansi.max' => 'Instansi maksimal 255 karakter.',

            'judul_kegiatan.required' => 'Judul kegiatan wajib diisi.',
            'judul_kegiatan.max' => 'Judul kegiatan maksimal 255 karakter.',

            'tanggal.required' => 'Tanggal wajib diisi.',
            'tanggal.date' => 'Format tanggal tidak valid.',

            'waktu_mulai.required' => 'Waktu mulai wajib diisi.',
            'waktu_mulai.date_format' => 'Format waktu mulai harus HH:MM.',

            'waktu_selesai.required' => 'Waktu selesai wajib diisi.',
            'waktu_selesai.date_format' => 'Format waktu selesai harus HH:MM.',
            'waktu_selesai.after' => 'Waktu selesai harus setelah waktu mulai.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            /*
             * Ambil pemesanan yang akan diubah.
             */
            $pemesanan = DB::table('tb_pemesanan_ruangan')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$pemesanan) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Pemesanan ruangan tidak ditemukan.'
                ], 404);
            }

            /*
             * Pemesanan yang sudah dibatalkan
             * tidak boleh diedit kembali.
             */
            if ($pemesanan->status === 'dibatalkan') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Pemesanan yang sudah dibatalkan tidak dapat diubah.'
                ], 422);
            }

            /*
             * Ambil ruangan baru.
             */
            $ruangan = DB::table('tb_ruangan')
                ->where('id', $request->ruangan_id)
                ->lockForUpdate()
                ->first();

            if (!$ruangan) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan tidak ditemukan.'
                ], 404);
            }

            /*
             * Ruangan harus aktif untuk pemesanan.
             */
            if ($ruangan->status !== 'aktif') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan sedang nonaktif dan tidak dapat digunakan.'
                ], 422);
            }

            /*
             * Cek bentrok dengan pemesanan lain.
             *
             * ID pemesanan yang sedang diedit dikecualikan.
             */
            $bentrok = DB::table('tb_pemesanan_ruangan')
                ->where('ruangan_id', $request->ruangan_id)
                ->whereDate('tanggal', $request->tanggal)
                ->where('status', '!=', 'dibatalkan')
                ->where('id', '!=', $id)
                ->where('waktu_mulai', '<', $request->waktu_selesai)
                ->where('waktu_selesai', '>', $request->waktu_mulai)
                ->exists();

            if ($bentrok) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Ruangan sudah dipesan pada waktu tersebut. Silakan pilih waktu atau ruangan lain.'
                ], 422);
            }

            $sekarang = now();

            DB::table('tb_pemesanan_ruangan')
                ->where('id', $id)
                ->update([
                    'ruangan_id' => $request->ruangan_id,
                    'nama_pemesan' => trim($request->nama_pemesan),
                    'instansi' => $request->filled('instansi')
                        ? trim($request->instansi)
                        : null,
                    'judul_kegiatan' => trim($request->judul_kegiatan),
                    'tanggal' => $request->tanggal,
                    'waktu_mulai' => $request->waktu_mulai,
                    'waktu_selesai' => $request->waktu_selesai,
                    'catatan' => $request->filled('catatan')
                        ? trim($request->catatan)
                        : null,
                    'updated_at' => $sekarang,
                ]);

            DB::commit();

            /*
             * Ambil kembali data terbaru.
             */
            $data = DB::table('tb_pemesanan_ruangan as p')
                ->join('tb_ruangan as r', 'r.id', '=', 'p.ruangan_id')
                ->select(
                    'p.id',
                    'p.ruangan_id',
                    'r.nama_ruangan',
                    'r.lokasi',
                    'r.kapasitas',
                    'p.nama_pemesan',
                    'p.instansi',
                    'p.judul_kegiatan',
                    'p.tanggal',
                    'p.waktu_mulai',
                    'p.waktu_selesai',
                    'p.status',
                    'p.catatan',
                    'p.created_at',
                    'p.updated_at'
                )
                ->where('p.id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Pemesanan ruangan berhasil diperbarui.',
                'data' => $data
            ], 200);

        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pemesanan ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Membatalkan pemesanan ruangan.
     *
     * Status dibatalkan dilakukan secara khusus,
     * bukan melalui update() biasa.
     */
    public function batal($id)
    {
        try {
            $pemesanan = DB::table('tb_pemesanan_ruangan')
                ->where('id', $id)
                ->first();

            if (!$pemesanan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pemesanan ruangan tidak ditemukan.'
                ], 404);
            }

            if ($pemesanan->status === 'dibatalkan') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pemesanan ruangan sudah dibatalkan.'
                ], 422);
            }

            if ($pemesanan->status === 'selesai') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pemesanan yang sudah selesai tidak dapat dibatalkan.'
                ], 422);
            }

            DB::table('tb_pemesanan_ruangan')
                ->where('id', $id)
                ->update([
                    'status' => 'dibatalkan',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Pemesanan ruangan berhasil dibatalkan.'
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan pemesanan ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Menghapus pemesanan ruangan.
     */
    public function destroy($id)
    {
        try {
            $pemesanan = DB::table('tb_pemesanan_ruangan')
                ->where('id', $id)
                ->first();

            if (!$pemesanan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pemesanan ruangan tidak ditemukan.'
                ], 404);
            }

            DB::table('tb_pemesanan_ruangan')
                ->where('id', $id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Pemesanan ruangan berhasil dihapus.'
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pemesanan ruangan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}