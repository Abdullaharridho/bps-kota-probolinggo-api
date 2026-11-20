<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

class LaporanKegiatanController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = DB::table('tb_laporan_kegiatan as l')
            ->join('tb_penugasan as p', 'p.id', '=', 'l.penugasan_id')
            ->join('tb_kegiatan as k', 'k.id', '=', 'p.kegiatan_id')
            ->join('users as u', 'u.id', '=', 'l.dilaporkan_oleh')
            ->select(
                'l.id',
                'l.penugasan_id',
                'l.catatan',
                'l.dilaporkan_oleh',
                'l.tanggal_laporan',
                'l.created_at',
                'l.updated_at',
                'p.ditugaskan_kepada',
                'p.ditugaskan_oleh',
                'p.status as status_penugasan',
                'k.id as kegiatan_id',
                'k.nama_kegiatan',
                'k.lokasi',
                'k.tanggal_mulai',
                'k.tanggal_selesai',
                'k.status as status_kegiatan',
                'u.name as nama_pelapor'
            );

        $this->terapkanHakAkses($query, $user);

        if ($request->filled('kegiatan_id')) {
            $query->where('k.id', $request->kegiatan_id);
        }

        if ($request->filled('penugasan_id')) {
            $query->where('l.penugasan_id', $request->penugasan_id);
        }

        $laporan = $query
            ->orderByDesc('l.tanggal_laporan')
            ->get();

        foreach ($laporan as $item) {
            $item->dokumen = $this->getDokumen($item->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Daftar laporan kegiatan berhasil diambil.',
            'data' => $laporan
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();

        $query = DB::table('tb_laporan_kegiatan as l')
            ->join('tb_penugasan as p', 'p.id', '=', 'l.penugasan_id')
            ->join('tb_kegiatan as k', 'k.id', '=', 'p.kegiatan_id')
            ->join('users as u', 'u.id', '=', 'l.dilaporkan_oleh')
            ->where('l.id', $id)
            ->select(
                'l.*',
                'p.ditugaskan_kepada',
                'p.ditugaskan_oleh',
                'p.status as status_penugasan',
                'k.id as kegiatan_id',
                'k.tim_id',
                'k.nama_kegiatan',
                'k.uraian_kegiatan',
                'k.lokasi',
                'k.tanggal_mulai',
                'k.tanggal_selesai',
                'k.volume_jam',
                'k.jam_mulai',
                'k.jam_selesai',
                'k.status as status_kegiatan',
                'u.name as nama_pelapor'
            );

        $this->terapkanHakAkses($query, $user);

        $laporan = $query->first();

        if (!$laporan) {
            return response()->json([
                'success' => false,
                'message' => 'Laporan kegiatan tidak ditemukan.'
            ], 404);
        }

        $laporan->dokumen = $this->getDokumen($laporan->id);

        return response()->json([
            'success' => true,
            'message' => 'Detail laporan kegiatan berhasil diambil.',
            'data' => $laporan
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'pegawai') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pegawai yang dapat mengisi laporan kegiatan.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'penugasan_id' => ['required', 'integer', 'exists:tb_penugasan,id'],
            'catatan' => ['required', 'string'],
            'dokumen' => ['required', 'array', 'min:1'],
            'dokumen.*.foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'dokumen.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'dokumen.*.longitude' => ['required', 'numeric', 'between:-180,180'],
        ], [
            'penugasan_id.required' => 'Penugasan wajib dipilih.',
            'penugasan_id.exists' => 'Penugasan tidak ditemukan.',
            'catatan.required' => 'Catatan laporan wajib diisi.',
            'dokumen.required' => 'Dokumentasi foto wajib diunggah.',
            'dokumen.min' => 'Minimal unggah satu foto dokumentasi.',
            'dokumen.*.foto.required' => 'Foto dokumentasi wajib diunggah.',
            'dokumen.*.foto.image' => 'File harus berupa gambar.',
            'dokumen.*.foto.mimes' => 'Format gambar harus JPG, JPEG, PNG, atau WEBP.',
            'dokumen.*.foto.max' => 'Ukuran setiap foto maksimal 5 MB.',
            'dokumen.*.latitude.required' => 'Latitude wajib diisi.',
            'dokumen.*.latitude.numeric' => 'Latitude harus berupa angka.',
            'dokumen.*.latitude.between' => 'Nilai latitude tidak valid.',
            'dokumen.*.longitude.required' => 'Longitude wajib diisi.',
            'dokumen.*.longitude.numeric' => 'Longitude harus berupa angka.',
            'dokumen.*.longitude.between' => 'Nilai longitude tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi laporan kegiatan gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $filePaths = [];

        try {
            $laporanId = DB::transaction(function () use (
                $request,
                $user,
                $validated,
                &$filePaths
            ) {
                $penugasan = DB::table('tb_penugasan')
                    ->where('id', $validated['penugasan_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$penugasan) {
                    throw ValidationException::withMessages([
                        'penugasan_id' => ['Penugasan tidak ditemukan.']
                    ]);
                }

                if ((int) $penugasan->ditugaskan_kepada !== (int) $user->id) {
                    throw new AuthorizationException(
                        'Anda tidak berhak mengisi laporan penugasan ini.'
                    );
                }

                if ($penugasan->status !== 'proses') {
                    throw ValidationException::withMessages([
                        'penugasan_id' => ['Penugasan tidak berada dalam status proses.']
                    ]);
                }

                $sudahAda = DB::table('tb_laporan_kegiatan')
                    ->where('penugasan_id', $penugasan->id)
                    ->exists();

                if ($sudahAda) {
                    throw ValidationException::withMessages([
                        'penugasan_id' => ['Laporan untuk penugasan ini sudah pernah dibuat.']
                    ]);
                }

                $kegiatan = DB::table('tb_kegiatan')
                    ->where('id', $penugasan->kegiatan_id)
                    ->lockForUpdate()
                    ->first();

                if (!$kegiatan) {
                    throw ValidationException::withMessages([
                        'penugasan_id' => ['Kegiatan dari penugasan ini tidak ditemukan.']
                    ]);
                }

                $now = now();

                $laporanId = DB::table('tb_laporan_kegiatan')
                    ->insertGetId([
                        'penugasan_id' => $penugasan->id,
                        'catatan' => $validated['catatan'],
                        'dilaporkan_oleh' => $user->id,
                        'tanggal_laporan' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                $this->simpanDokumen(
                    $validated['dokumen'],
                    $laporanId,
                    $user->id,
                    $filePaths
                );

                DB::table('tb_penugasan')
                    ->where('id', $penugasan->id)
                    ->update([
                        'status' => 'selesai',
                        'updated_at' => $now,
                    ]);

                $this->perbaruiStatusKegiatan($penugasan->kegiatan_id);

                return $laporanId;
            });

            return response()->json([
                'success' => true,
                'message' => 'Laporan kegiatan dan dokumentasi berhasil disimpan.',
                'data' => $this->getDetailLaporan($laporanId)
            ], 201);

        } catch (ValidationException $e) {
            $this->hapusDokumenDrive($filePaths);

            return response()->json([
                'success' => false,
                'message' => 'Validasi laporan kegiatan gagal.',
                'errors' => $e->errors()
            ], 422);

        } catch (AuthorizationException $e) {
            $this->hapusDokumenDrive($filePaths);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);

        } catch (Throwable $e) {
            $this->hapusDokumenDrive($filePaths);

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan laporan kegiatan.'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();

        if ($user->role !== 'pegawai') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pegawai yang dapat mengedit laporan kegiatan.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'catatan' => ['required', 'string'],
            'dokumen' => ['sometimes', 'required', 'array', 'min:1'],
            'dokumen.*.foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'dokumen.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'dokumen.*.longitude' => ['required', 'numeric', 'between:-180,180'],
        ], [
            'catatan.required' => 'Catatan laporan wajib diisi.',
            'dokumen.min' => 'Minimal unggah satu foto dokumentasi.',
            'dokumen.*.foto.required' => 'Foto dokumentasi wajib diunggah.',
            'dokumen.*.foto.image' => 'File harus berupa gambar.',
            'dokumen.*.foto.mimes' => 'Format gambar harus JPG, JPEG, PNG, atau WEBP.',
            'dokumen.*.foto.max' => 'Ukuran setiap foto maksimal 5 MB.',
            'dokumen.*.latitude.required' => 'Latitude wajib diisi.',
            'dokumen.*.latitude.numeric' => 'Latitude harus berupa angka.',
            'dokumen.*.latitude.between' => 'Nilai latitude tidak valid.',
            'dokumen.*.longitude.required' => 'Longitude wajib diisi.',
            'dokumen.*.longitude.numeric' => 'Longitude harus berupa angka.',
            'dokumen.*.longitude.between' => 'Nilai longitude tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi laporan kegiatan gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $filePaths = [];
        $oldFilePaths = [];

        try {
            DB::transaction(function () use (
                $request,
                $user,
                $id,
                $validated,
                &$filePaths,
                &$oldFilePaths
            ) {
                $laporan = DB::table('tb_laporan_kegiatan')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$laporan) {
                    throw ValidationException::withMessages([
                        'laporan' => ['Laporan kegiatan tidak ditemukan.']
                    ]);
                }

                $penugasan = DB::table('tb_penugasan')
                    ->where('id', $laporan->penugasan_id)
                    ->lockForUpdate()
                    ->first();

                if (!$penugasan) {
                    throw ValidationException::withMessages([
                        'laporan' => ['Penugasan tidak ditemukan.']
                    ]);
                }

                if ((int) $penugasan->ditugaskan_kepada !== (int) $user->id) {
                    throw new AuthorizationException(
                        'Anda tidak berhak mengedit laporan ini.'
                    );
                }

                if ((int) $laporan->dilaporkan_oleh !== (int) $user->id) {
                    throw new AuthorizationException(
                        'Anda hanya dapat mengedit laporan yang Anda buat.'
                    );
                }

                DB::table('tb_kegiatan')
                    ->where('id', $penugasan->kegiatan_id)
                    ->lockForUpdate()
                    ->first();

                $now = now();

                DB::table('tb_laporan_kegiatan')
                    ->where('id', $id)
                    ->update([
                        'catatan' => $validated['catatan'],
                        'updated_at' => $now,
                    ]);

                if (array_key_exists('dokumen', $validated)) {
                    $dokumenLama = DB::table('tb_dokumen_kegiatan')
                        ->where('laporan_kegiatan_id', $id)
                        ->get();

                    foreach ($dokumenLama as $item) {
                        $oldFilePaths[] = $item->path_file;
                    }

                    DB::table('tb_dokumen_kegiatan')
                        ->where('laporan_kegiatan_id', $id)
                        ->delete();

                    $this->simpanDokumen(
                        $validated['dokumen'],
                        $id,
                        $user->id,
                        $filePaths
                    );
                }

                DB::table('tb_penugasan')
                    ->where('id', $penugasan->id)
                    ->update([
                        'status' => 'selesai',
                        'updated_at' => $now,
                    ]);

                $this->perbaruiStatusKegiatan($penugasan->kegiatan_id);
            });

            // Hapus foto lama setelah transaksi database berhasil
            if (!empty($oldFilePaths)) {
                $this->hapusDokumenDrive($oldFilePaths);
            }

            return response()->json([
                'success' => true,
                'message' => 'Laporan kegiatan berhasil diperbarui.',
                'data' => $this->getDetailLaporan($id)
            ]);

        } catch (ValidationException $e) {
            $this->hapusDokumenDrive($filePaths);

            return response()->json([
                'success' => false,
                'message' => 'Validasi laporan kegiatan gagal.',
                'errors' => $e->errors()
            ], 422);

        } catch (AuthorizationException $e) {
            $this->hapusDokumenDrive($filePaths);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);

        } catch (Throwable $e) {
            $this->hapusDokumenDrive($filePaths);

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui laporan kegiatan.'
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        if ($user->role !== 'pegawai') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pegawai yang dapat menghapus laporan kegiatan.'
            ], 403);
        }

        $filePaths = [];

        try {
            DB::transaction(function () use (
                $user,
                $id,
                &$filePaths
            ) {
                $laporan = DB::table('tb_laporan_kegiatan')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$laporan) {
                    throw ValidationException::withMessages([
                        'laporan' => ['Laporan kegiatan tidak ditemukan.']
                    ]);
                }

                $penugasan = DB::table('tb_penugasan')
                    ->where('id', $laporan->penugasan_id)
                    ->lockForUpdate()
                    ->first();

                if (!$penugasan) {
                    throw ValidationException::withMessages([
                        'laporan' => ['Penugasan tidak ditemukan.']
                    ]);
                }

                if (
                    (int) $penugasan->ditugaskan_kepada !== (int) $user->id ||
                    (int) $laporan->dilaporkan_oleh !== (int) $user->id
                ) {
                    throw new AuthorizationException(
                        'Anda hanya dapat menghapus laporan milik sendiri.'
                    );
                }

                DB::table('tb_kegiatan')
                    ->where('id', $penugasan->kegiatan_id)
                    ->lockForUpdate()
                    ->first();

                $dokumen = DB::table('tb_dokumen_kegiatan')
                    ->where('laporan_kegiatan_id', $id)
                    ->get();

                foreach ($dokumen as $item) {
                    $filePaths[] = $item->path_file;
                }

                DB::table('tb_laporan_kegiatan')
                    ->where('id', $id)
                    ->delete();

                DB::table('tb_penugasan')
                    ->where('id', $penugasan->id)
                    ->update([
                        'status' => 'proses',
                        'updated_at' => now(),
                    ]);

                DB::table('tb_kegiatan')
                    ->where('id', $penugasan->kegiatan_id)
                    ->update([
                        'status' => 'proses',
                        'updated_at' => now(),
                    ]);
            });

            // Hapus foto Drive setelah transaksi berhasil
            if (!empty($filePaths)) {
                $this->hapusDokumenDrive($filePaths);
            }

            return response()->json([
                'success' => true,
                'message' => 'Laporan kegiatan berhasil dihapus. Status penugasan dan kegiatan kembali menjadi proses.'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Laporan kegiatan tidak ditemukan.',
                'errors' => $e->errors()
            ], 404);

        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus laporan kegiatan.'
            ], 500);
        }
    }

    /**
     * Menyimpan dokumentasi ke Google Drive.
     * path_file berisi Google Drive File ID.
     */
    private function simpanDokumen(
        array $dokumen,
        int $laporanId,
        int $userId,
        array &$filePaths
    ): void {
        $googleDrive = app(GoogleDriveService::class);

        foreach ($dokumen as $item) {
            $foto = $item['foto'];

            $fileId = $googleDrive->upload($foto);

            if (!$fileId) {
                throw new \RuntimeException(
                    'Gagal menyimpan foto dokumentasi ke Google Drive.'
                );
            }

            // Dicatat untuk cleanup jika transaksi database gagal
            $filePaths[] = $fileId;

            DB::table('tb_dokumen_kegiatan')->insert([
                'laporan_kegiatan_id' => $laporanId,
                'diunggah_oleh' => $userId,
                'nama_file' => $foto->getClientOriginalName(),
                'path_file' => $fileId,
                'mime_type' => $foto->getMimeType(),
                'ukuran_file' => $foto->getSize(),
                'latitude' => $item['latitude'],
                'longitude' => $item['longitude'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Menghapus file dari Google Drive.
     */
    private function hapusDokumenDrive(array $fileIds): void
    {
        if (empty($fileIds)) {
            return;
        }

        try {
            $googleDrive = app(GoogleDriveService::class);

            foreach (array_unique($fileIds) as $fileId) {
                if (empty($fileId)) {
                    continue;
                }

                try {
                    $googleDrive->delete($fileId);
                } catch (Throwable $e) {
                    // Jangan membatalkan proses database karena gagal hapus Drive
                    report($e);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Preview gambar melalui Laravel.
     * File Google Drive tetap privat.
     */
    public function previewDokumen(Request $request, $id)
    {
        $user = $request->user();

        $query = DB::table('tb_dokumen_kegiatan as d')
            ->join(
                'tb_laporan_kegiatan as l',
                'l.id',
                '=',
                'd.laporan_kegiatan_id'
            )
            ->join(
                'tb_penugasan as p',
                'p.id',
                '=',
                'l.penugasan_id'
            )
            ->join(
                'tb_kegiatan as k',
                'k.id',
                '=',
                'p.kegiatan_id'
            )
            ->where('d.id', $id)
            ->select(
                'd.id',
                'd.nama_file',
                'd.path_file',
                'd.mime_type'
            );

        $this->terapkanHakAkses($query, $user);

        $dokumen = $query->first();

        if (!$dokumen) {
            return response()->json([
                'success' => false,
                'message' => 'Dokumen tidak ditemukan.'
            ], 404);
        }

        try {
            $googleDrive = app(GoogleDriveService::class);

            $file = $googleDrive->download($dokumen->path_file);

            $namaFile = str_replace(
                ["\r", "\n", '"'],
                '',
                $dokumen->nama_file
            );

            return response()->stream(
                function () use ($file) {
                    echo $file->getBody()->getContents();
                },
                200,
                [
                    'Content-Type' => $dokumen->mime_type
                        ?: 'application/octet-stream',
                    'Content-Disposition' => 'inline; filename="' .
                        $namaFile . '"',
                    'Cache-Control' => 'private, no-store',
                ]
            );

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil gambar dari Google Drive.'
            ], 500);
        }
    }

    private function perbaruiStatusKegiatan(int $kegiatanId): void
    {
        $masihAdaPenugasan = DB::table('tb_penugasan')
            ->where('kegiatan_id', $kegiatanId)
            ->where('status', '!=', 'selesai')
            ->exists();

        DB::table('tb_kegiatan')
            ->where('id', $kegiatanId)
            ->update([
                'status' => $masihAdaPenugasan ? 'proses' : 'selesai',
                'updated_at' => now(),
            ]);
    }

    /**
     * Mengambil metadata dokumen beserta URL preview.
     * Google Drive File ID tidak dikirim ke aplikasi Android.
     */
    private function getDokumen(int $laporanId)
    {
        return DB::table('tb_dokumen_kegiatan')
            ->where('laporan_kegiatan_id', $laporanId)
            ->select(
                'id',
                'laporan_kegiatan_id',
                'diunggah_oleh',
                'nama_file',
                'mime_type',
                'ukuran_file',
                'latitude',
                'longitude',
                'created_at'
            )
            ->orderBy('id')
            ->get()
            ->map(function ($item) {
                $item->preview_url = request()->getSchemeAndHttpHost()
    . '/api/laporan-kegiatan/dokumen/' . $item->id . '/preview';

                return $item;
            });
    }

    private function getDetailLaporan(int $id)
    {
        $laporan = DB::table('tb_laporan_kegiatan as l')
            ->join('tb_penugasan as p', 'p.id', '=', 'l.penugasan_id')
            ->join('tb_kegiatan as k', 'k.id', '=', 'p.kegiatan_id')
            ->join('users as u', 'u.id', '=', 'l.dilaporkan_oleh')
            ->where('l.id', $id)
            ->select(
                'l.*',
                'p.ditugaskan_kepada',
                'p.ditugaskan_oleh',
                'p.status as status_penugasan',
                'k.id as kegiatan_id',
                'k.nama_kegiatan',
                'k.uraian_kegiatan',
                'k.lokasi',
                'k.tanggal_mulai',
                'k.tanggal_selesai',
                'k.volume_jam',
                'k.status as status_kegiatan',
                'u.name as nama_pelapor'
            )
            ->first();

        if ($laporan) {
            $laporan->dokumen = $this->getDokumen($id);
        }

        return $laporan;
    }

    private function terapkanHakAkses($query, $user): void
    {
        if ($user->role === 'pegawai') {
            $query->where('p.ditugaskan_kepada', $user->id);

        } elseif ($user->role === 'ketua_tim') {
            $query->whereExists(function ($subquery) use ($user) {
                $subquery->select(DB::raw(1))
                    ->from('tb_tim as t')
                    ->whereColumn('t.id', 'k.tim_id')
                    ->where('t.ketua_id', $user->id);
            });

        } elseif (!in_array($user->role, [
            'super_admin',
            'pimpinan',
            'operator'
        ])) {
            abort(403, 'Anda tidak memiliki akses untuk melihat laporan kegiatan.');
        }
    }
}
