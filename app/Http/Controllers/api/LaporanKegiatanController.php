<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // Maksimal 5 dokumen.
        'dokumen' => 'required|array|max:5',

        // Format file yang diizinkan, maksimal 5 MB per file.
        'dokumen.*.file' => [
            'required',
            'file',
            'mimes:jpeg,png,jpg,pdf,doc,docx',
            'max:5120'
        ],

        'dokumen.*.jenis_dokumen' => [
            'required',
            Rule::in([
                'materi',
                'dokumentasi',
                'notulen',
                'lainnya'
            ])
        ]
    ]);

    $uploadedPaths = [];

    DB::beginTransaction();

    try {
        // Ambil data penugasan untuk mengetahui surat masuk terkait.
        $penugasan = DB::table('tb_penugasan')
            ->where('id', $validated['penugasan_id'])
            ->lockForUpdate()
            ->first();

        if (!$penugasan) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Data penugasan tidak ditemukan.'
            ], 404);
        }

        // Simpan laporan kegiatan.
        $laporanId = DB::table('tb_laporan_kegiatan')
            ->insertGetId([
                'penugasan_id' => $validated['penugasan_id'],
                'ringkasan' => $validated['ringkasan'],
                'hasil_kegiatan' => $validated['hasil_kegiatan'],
                'catatan' => $validated['catatan'] ?? null,
                'dilaporkan_oleh' => $request->user()->id,
                'tanggal_laporan' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

        // Simpan dokumen lampiran.
        foreach ($request->file('dokumen', []) as $index => $docData) {
            $file = $docData['file'];

            $jenis = $request->input(
                "dokumen.$index.jenis_dokumen"
            );

            $path = $file->store(
                'dokumen_kegiatan',
                'public'
            );

            if (!$path) {
                throw new \RuntimeException(
                    'Gagal menyimpan dokumen lampiran.'
                );
            }

            $uploadedPaths[] = $path;

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

        // Ubah status penugasan menjadi selesai.
        DB::table('tb_penugasan')
            ->where('id', $validated['penugasan_id'])
            ->update([
                'status' => 'selesai',
                'updated_at' => now()
            ]);

        /*
         * Ubah status surat masuk menjadi selesai.
         *
         * Asumsi: tb_penugasan memiliki kolom surat_masuk_id
         * yang merujuk ke tb_surat_masuk.id.
         */
        if (
            isset($penugasan->surat_masuk_id) &&
            $penugasan->surat_masuk_id !== null
        ) {
            DB::table('tb_surat_masuk')
                ->where('id', $penugasan->surat_masuk_id)
                ->update([
                    'status' => 'selesai',
                    'updated_at' => now()
                ]);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Laporan berhasil disubmit. Status penugasan dan surat masuk telah diperbarui.',
            'data' => [
                'laporan_id' => $laporanId,
                'penugasan_id' => $validated['penugasan_id'],
                'status_penugasan' => 'selesai',
                'status_surat_masuk' => isset($penugasan->surat_masuk_id)
                    && $penugasan->surat_masuk_id !== null
                        ? 'selesai'
                        : null
            ]
        ], 201);

    } catch (\Throwable $e) {
        DB::rollBack();

        // Hapus file yang sudah tersimpan jika transaksi gagal.
        foreach ($uploadedPaths as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $fileException) {
                report($fileException);
            }
        }

        report($e);

        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan saat menyimpan laporan kegiatan.'
        ], 500);
    }
}

public function update(Request $request, string $id)
{
    $laporan = DB::table('tb_laporan_kegiatan')
        ->where('id', $id)
        ->first();

    if (!$laporan) {
        return response()->json([
            'success' => false,
            'message' => 'Data laporan tidak ditemukan.'
        ], 404);
    }

    $validated = $request->validate([
        'penugasan_id' => [
            'required',
            'exists:tb_penugasan,id'
        ],
        'ringkasan' => 'required|string',
        'hasil_kegiatan' => 'required|string',
        'catatan' => 'nullable|string',

        // File baru bersifat opsional saat edit.
        'dokumen' => 'sometimes|array',
        'dokumen.*.file' => [
            'required',
            'file',
            'mimes:jpeg,png,jpg,pdf,doc,docx',
            'max:5120'
        ],
        'dokumen.*.jenis_dokumen' => [
            'required',
            Rule::in([
                'materi',
                'dokumentasi',
                'notulen',
                'lainnya'
            ])
        ],

        // ID dokumen lama yang ingin dihapus atau diganti.
        'dokumen_hapus' => 'sometimes|array',
        'dokumen_hapus.*' => [
            'required',
            'integer',
            'distinct'
        ]
    ]);

    $idLaporan = (int) $id;
    $idDokumenHapus = array_map(
        'intval',
        $validated['dokumen_hapus'] ?? []
    );

    $dokumenFiles = $request->file('dokumen', []);

    $pathsFileBaru = [];
    $pathsFileLama = [];

    DB::beginTransaction();

    try {
        // Kunci laporan untuk menghindari perubahan bersamaan.
        $laporanTerkunci = DB::table('tb_laporan_kegiatan')
            ->where('id', $idLaporan)
            ->lockForUpdate()
            ->first();

        if (!$laporanTerkunci) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Data laporan tidak ditemukan.'
            ], 404);
        }

        // Ambil dan kunci dokumen milik laporan ini.
        $dokumenLama = DB::table('tb_dokumen_kegiatan')
            ->where('laporan_kegiatan_id', $idLaporan)
            ->lockForUpdate()
            ->get();

        $dokumenHapus = $dokumenLama->whereIn(
            'id',
            $idDokumenHapus
        );

        // Pastikan semua ID yang diminta benar-benar milik laporan ini.
        if ($dokumenHapus->count() !== count($idDokumenHapus)) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ada dokumen yang tidak ditemukan atau bukan milik laporan ini.'
            ], 422);
        }

        $jumlahDokumenAkhir =
            $dokumenLama->count()
            - $dokumenHapus->count()
            + count($dokumenFiles);

        if ($jumlahDokumenAkhir > 5) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Maksimal 5 dokumen untuk setiap laporan. Hapus dokumen lama atau kurangi dokumen baru.'
            ], 422);
        }

        // Perbarui isi laporan.
        DB::table('tb_laporan_kegiatan')
            ->where('id', $idLaporan)
            ->update([
                'penugasan_id' => $validated['penugasan_id'],
                'ringkasan' => $validated['ringkasan'],
                'hasil_kegiatan' => $validated['hasil_kegiatan'],
                'catatan' => $validated['catatan'] ?? null,
                'updated_at' => now()
            ]);

        // Hapus data dokumen yang dipilih untuk dihapus/diganti.
        if ($idDokumenHapus !== []) {
            $pathsFileLama = $dokumenHapus
                ->pluck('path_file')
                ->filter()
                ->values()
                ->all();

            DB::table('tb_dokumen_kegiatan')
                ->where('laporan_kegiatan_id', $idLaporan)
                ->whereIn('id', $idDokumenHapus)
                ->delete();
        }

        // Simpan dokumen baru.
        foreach ($dokumenFiles as $index => $docData) {
            $file = $docData['file'] ?? null;

            if (!$file) {
                throw new \RuntimeException(
                    'File dokumen baru tidak ditemukan.'
                );
            }

            $jenis = $request->input(
                "dokumen.$index.jenis_dokumen"
            );

            $path = $file->store(
                'dokumen_kegiatan',
                'public'
            );

            if (!$path) {
                throw new \RuntimeException(
                    'Gagal menyimpan file dokumen.'
                );
            }

            $pathsFileBaru[] = $path;

            DB::table('tb_dokumen_kegiatan')->insert([
                'laporan_kegiatan_id' => $idLaporan,
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

        DB::commit();

        // Hapus file lama hanya setelah perubahan database berhasil.
        foreach ($pathsFileLama as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Laporan dan dokumen berhasil diperbarui.'
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();

        // Bersihkan file baru jika proses database gagal.
        foreach ($pathsFileBaru as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $fileException) {
                report($fileException);
            }
        }

        report($e);

        return response()->json([
            'success' => false,
            'message' => 'Gagal memperbarui laporan kegiatan.'
        ], 500);
    }
}
    
public function destroy(string $id)
{
    $paths = [];

    DB::beginTransaction();

    try {
        // 1. Cari laporan dan kunci data selama transaksi.
        $laporan = DB::table('tb_laporan_kegiatan')
            ->where('id', $id)
            ->lockForUpdate()
            ->first();

        if (!$laporan) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Data laporan tidak ditemukan.'
            ], 404);
        }

        $idPenugasan = $laporan->penugasan_id;

        // 2. Ambil penugasan untuk mengetahui surat masuk terkait.
        $penugasan = DB::table('tb_penugasan')
            ->where('id', $idPenugasan)
            ->lockForUpdate()
            ->first();

        // 3. Ambil path seluruh dokumen yang dilampirkan.
        $dokumen = DB::table('tb_dokumen_kegiatan')
            ->where('laporan_kegiatan_id', $id)
            ->get();

        $paths = $dokumen
            ->pluck('path_file')
            ->filter()
            ->values()
            ->all();

        // 4. Hapus data dokumen dari database.
        DB::table('tb_dokumen_kegiatan')
            ->where('laporan_kegiatan_id', $id)
            ->delete();

        // 5. Hapus laporan dari database.
        DB::table('tb_laporan_kegiatan')
            ->where('id', $id)
            ->delete();

        // 6. Periksa apakah masih ada laporan untuk penugasan ini.
        $masihAdaLaporan = DB::table('tb_laporan_kegiatan')
            ->where('penugasan_id', $idPenugasan)
            ->exists();

        // 7. Jika tidak ada laporan tersisa, kembalikan status penugasan.
        if (!$masihAdaLaporan) {
            DB::table('tb_penugasan')
                ->where('id', $idPenugasan)
                ->where('status', 'selesai')
                ->update([
                    'status' => 'diterima',
                    'updated_at' => now()
                ]);
        }

        // 8. Perbarui status surat masuk jika penugasan memiliki surat terkait.
        $statusSuratMasuk = null;

        if (
            $penugasan &&
            !empty($penugasan->surat_masuk_id)
        ) {
            // Periksa apakah masih ada penugasan yang belum selesai.
            $adaPenugasanBelumSelesai = DB::table('tb_penugasan')
                ->where('surat_masuk_id', $penugasan->surat_masuk_id)
                ->where('status', '!=', 'selesai')
                ->exists();

            if ($adaPenugasanBelumSelesai) {
                // Setidaknya satu penugasan belum selesai.
                DB::table('tb_surat_masuk')
                    ->where('id', $penugasan->surat_masuk_id)
                    ->where('status', 'selesai')
                    ->update([
                        'status' => 'diproses',
                        'updated_at' => now()
                    ]);

                $statusSuratMasuk = 'diproses';
            } else {
                // Seluruh penugasan masih berstatus selesai.
                $statusSuratMasuk = 'selesai';
            }
        }

        // 9. Simpan seluruh perubahan database.
        DB::commit();

        // 10. Hapus file fisik setelah transaksi berhasil.
        foreach ($paths as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Laporan kegiatan berhasil dihapus.',
            'status_penugasan' => $masihAdaLaporan
                ? 'tetap'
                : 'diterima',
            'status_surat_masuk' => $statusSuratMasuk
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();

        // Catat kesalahan untuk pemeriksaan log server.
        report($e);

        return response()->json([
            'success' => false,
            'message' => 'Gagal menghapus laporan kegiatan.'
        ], 500);
    }
}
}
