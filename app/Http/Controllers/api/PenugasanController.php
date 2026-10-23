<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class PenugasanController extends Controller
{
    /**
     * Daftar penugasan.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = DB::table('tb_penugasan')
            ->join(
                'tb_surat_masuk',
                'tb_penugasan.surat_masuk_id',
                '=',
                'tb_surat_masuk.id'
            )
            ->join(
                'users as pengirim',
                'tb_penugasan.ditugaskan_oleh',
                '=',
                'pengirim.id'
            )
            ->join(
                'users as penerima',
                'tb_penugasan.ditugaskan_kepada',
                '=',
                'penerima.id'
            )
            ->select(
                'tb_penugasan.*',
                'tb_surat_masuk.nomor_surat',
                'tb_surat_masuk.perihal',
                'tb_surat_masuk.tanggal_acara',
                'tb_surat_masuk.waktu_mulai',
                'tb_surat_masuk.waktu_selesai',
                'pengirim.name as nama_pengirim',
                'tb_surat_masuk.lokasi',
                'penerima.name as nama_penerima'
            )
            ->orderByDesc('tb_penugasan.created_at')
            ->orderByDesc('tb_penugasan.id');

        if (!in_array($user->role, ['super_admin', 'pimpinan'])) {
            $query->where(function ($q) use ($user) {
                $q->where(
                    'tb_penugasan.ditugaskan_kepada',
                    $user->id
                )->orWhere(
                    'tb_penugasan.ditugaskan_oleh',
                    $user->id
                );
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where(
                    'tb_surat_masuk.perihal',
                    'like',
                    "%{$search}%"
                )->orWhere(
                    'tb_surat_masuk.nomor_surat',
                    'like',
                    "%{$search}%"
                )->orWhere(
                    'penerima.name',
                    'like',
                    "%{$search}%"
                );
            });
        }

        if ($request->filled('status')) {
            $query->where(
                'tb_penugasan.status',
                $request->status
            );
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    /**
     * Detail satu penugasan.
     */
    public function show(string $id)
    {
        $penugasan = DB::table('tb_penugasan')
            ->join(
                'tb_surat_masuk',
                'tb_penugasan.surat_masuk_id',
                '=',
                'tb_surat_masuk.id'
            )
            ->join(
                'users as pengirim',
                'tb_penugasan.ditugaskan_oleh',
                '=',
                'pengirim.id'
            )
            ->join(
                'users as penerima',
                'tb_penugasan.ditugaskan_kepada',
                '=',
                'penerima.id'
            )
            ->select(
                'tb_penugasan.*',
                'tb_surat_masuk.nomor_surat',
                'tb_surat_masuk.perihal',
                'tb_surat_masuk.tanggal_acara',
                'tb_surat_masuk.waktu_mulai',
                'tb_surat_masuk.waktu_selesai',
                'pengirim.name as nama_pengirim',
                'tb_surat_masuk.lokasi',
                'penerima.name as nama_penerima'
            )
            ->where('tb_penugasan.id', $id)
            ->first();

        if (!$penugasan) {
            return response()->json([
                'success' => false,
                'message' => 'Data penugasan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $penugasan,
        ]);
    }

    /**
     * Mengambil ID pegawai yang memiliki kegiatan harian
     * bentrok dengan jadwal acara surat masuk.
     *
     * Digunakan Android untuk menyaring pilihan penerima.
     */
    public function penerimaBentrok(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surat_masuk_id' => [
                'required',
                'integer',
                'exists:tb_surat_masuk,id',
            ],
        ]);

        $surat = DB::table('tb_surat_masuk')
            ->where('id', $validated['surat_masuk_id'])
            ->first();

        if (
            !$surat ||
            empty($surat->tanggal_acara) ||
            empty($surat->waktu_mulai) ||
            empty($surat->waktu_selesai)
        ) {
            return response()->json([
                'success' => true,
                'message' => 'Jadwal acara belum lengkap.',
                'data' => [
                    'user_ids' => [],
                ],
            ]);
        }

        $userIdsBentrok = DB::table('tb_kegiatan_harian')
            ->whereDate('tanggal', $surat->tanggal_acara)
            ->where('jam_mulai', '<', $surat->waktu_selesai)
            ->where('jam_selesai', '>', $surat->waktu_mulai)
            ->distinct()
            ->pluck('user_id')
            ->map(fn($id) => (int) $id)
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Daftar jadwal bentrok berhasil diambil.',
            'data' => [
                'user_ids' => $userIdsBentrok,
            ],
        ]);
    }

    /**
     * Membuat penugasan awal atau penugasan ulang.
     *
     * WhatsApp dikirim kepada pegawai yang ditunjuk
     * setelah transaksi database berhasil.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surat_masuk_id' => [
                'required',
                'integer',
                'exists:tb_surat_masuk,id',
            ],
            'ditugaskan_kepada' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'catatan_penugasan' => [
                'nullable',
                'string',
            ],
        ]);

        $user = $request->user();
        $suratMasukId = (int) $validated['surat_masuk_id'];

        $penugasan = DB::transaction(function () use (
            $validated,
            $user,
            $suratMasukId
        ) {
            // Mengunci surat agar dua permintaan bersamaan
            // tidak sama-sama membuat penugasan.
            $surat = DB::table('tb_surat_masuk')
                ->where('id', $suratMasukId)
                ->lockForUpdate()
                ->first();

            if (!$surat) {
                abort(404, 'Data surat masuk tidak ditemukan.');
            }

            // Ambil penugasan terakhir.
            $penugasanTerakhir = DB::table('tb_penugasan')
                ->where('surat_masuk_id', $suratMasukId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($surat->status === 'baru') {
                // Penugasan awal hanya boleh dilakukan jika
                // surat belum memiliki riwayat penugasan.
                if ($penugasanTerakhir) {
                    abort(
                        422,
                        'Surat sudah memiliki riwayat penugasan. ' .
                            'Periksa status penugasan terakhir.'
                    );
                }
            } elseif ($surat->status === 'diproses') {
                // Penugasan ulang hanya boleh dilakukan
                // setelah penugasan terakhir ditolak.
                if (!$penugasanTerakhir) {
                    abort(
                        422,
                        'Riwayat penugasan tidak ditemukan.'
                    );
                }

                if ($penugasanTerakhir->status !== 'ditolak') {
                    abort(
                        422,
                        'Penugasan ulang hanya dapat dilakukan ' .
                            'setelah penugasan terakhir ditolak.'
                    );
                }
            } else {
                abort(
                    422,
                    'Surat dengan status ' .
                        ($surat->status ?? 'tidak diketahui') .
                        ' tidak dapat ditugaskan.'
                );
            }

            /*
             * VALIDASI BENTROK JADWAL
             *
             * Dilakukan di backend supaya penugasan tetap
             * ditolak meskipun Android belum memperbarui
             * daftar penerima yang tersedia.
             */
            $penerimaId = (int) $validated['ditugaskan_kepada'];

            if (
                !empty($surat->tanggal_acara) &&
                !empty($surat->waktu_mulai) &&
                !empty($surat->waktu_selesai)
            ) {
                $jadwalBentrok = DB::table('tb_kegiatan_harian')
                    ->where('user_id', $penerimaId)
                    ->where('tanggal', $surat->tanggal_acara)
                    ->where(
                        'jam_mulai',
                        '<',
                        $surat->waktu_selesai
                    )
                    ->where(
                        'jam_selesai',
                        '>',
                        $surat->waktu_mulai
                    )
                    ->exists();

                if ($jadwalBentrok) {
                    abort(
                        422,
                        'Pegawai memiliki kegiatan harian yang ' .
                            'bentrok dengan jadwal acara surat masuk.'
                    );
                }
            }

            $waktuSekarang = now();

            // Selalu buat record baru agar riwayat lama
            // tetap tersimpan.
            $penugasanId = DB::table('tb_penugasan')
                ->insertGetId([
                    'surat_masuk_id' => $suratMasukId,
                    'ditugaskan_oleh' => $user->id,
                    'ditugaskan_kepada' => $penerimaId,
                    'catatan_penugasan' =>
                    $validated['catatan_penugasan'] ?? null,
                    'status' => 'menunggu_respon',
                    'waktu_penugasan' => $waktuSekarang,
                    'waktu_respon' => null,
                    'catatan_respon' => null,
                    'created_at' => $waktuSekarang,
                    'updated_at' => $waktuSekarang,
                ]);

            // Status surat berubah menjadi diproses
            // pada penugasan awal.
            if ($surat->status === 'baru') {
                DB::table('tb_surat_masuk')
                    ->where('id', $suratMasukId)
                    ->update([
                        'status' => 'diproses',
                        'updated_at' => $waktuSekarang,
                    ]);
            }

            return DB::table('tb_penugasan')
                ->where('id', $penugasanId)
                ->first();
        });

        // Transaksi sudah berhasil. Kirim ke satu pegawai
        // yang ditunjuk pada penugasan ini.
        $this->kirimNotifikasiPenugasan(
            (int) $penugasan->id,
            'baru'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Penugasan berhasil dibuat.',
            'data' => $penugasan,
        ], 201);
    }

    /**
     * Memperbarui data penugasan.
     */
    public function update(Request $request, $id)
    {
        $penugasan = DB::table('tb_penugasan')
            ->where('id', $id)
            ->first();

        if (!$penugasan) {
            return response()->json([
                'success' => false,
                'message' => 'Data penugasan tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'ditugaskan_kepada' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
            ],
            'catatan_penugasan' => [
                'nullable',
                'string',
            ],
        ]);

        /*
         * Endpoint update tetap mengikuti perilaku lama.
         * Notifikasi tidak dikirim dari endpoint ini.
         */
        $validated['updated_at'] = now();

        DB::table('tb_penugasan')
            ->where('id', $id)
            ->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Data penugasan berhasil diperbarui.',
            'data' => DB::table('tb_penugasan')
                ->where('id', $id)
                ->first(),
        ]);
    }

    /**
     * Respons pegawai terhadap penugasan.
     *
     * Setelah respons berhasil disimpan, WhatsApp dikirim
     * kepada Ketua Tim yang membuat penugasan.
     */
    public function responPenugasan(Request $request, $id)
    {
        $user = $request->user();

        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['diterima', 'ditolak']),
            ],
            'catatan_respon' => [
                'nullable',
                'string',
            ],
        ]);

        $hasil = DB::transaction(function () use (
            $id,
            $user,
            $validated
        ) {
            $penugasan = DB::table('tb_penugasan')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$penugasan) {
                return [
                    'http' => 404,
                    'body' => [
                        'success' => false,
                        'message' => 'Data penugasan tidak ditemukan.',
                    ],
                ];
            }

            if (
                (int) $penugasan->ditugaskan_kepada
                !== (int) $user->id
            ) {
                return [
                    'http' => 403,
                    'body' => [
                        'success' => false,
                        'message' =>
                        'Anda tidak berhak merespons penugasan ini.',
                    ],
                ];
            }

            // Penugasan hanya dapat direspons satu kali.
            if ($penugasan->status !== 'menunggu_respon') {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                        'Penugasan sudah direspons dan ' .
                            'tidak dapat diubah kembali.',
                    ],
                ];
            }

            $waktuSekarang = now();

            // Simpan respons pegawai.
            DB::table('tb_penugasan')
                ->where('id', $id)
                ->update([
                    'status' => $validated['status'],
                    'catatan_respon' =>
                    $validated['catatan_respon'] ?? null,
                    'waktu_respon' => $waktuSekarang,
                    'updated_at' => $waktuSekarang,
                ]);

            // Jika diterima, ubah status surat menjadi diproses.
            if ($validated['status'] === 'diterima') {
                DB::table('tb_surat_masuk')
                    ->where('id', $penugasan->surat_masuk_id)
                    ->update([
                        'status' => 'diproses',
                        'updated_at' => $waktuSekarang,
                    ]);
            }

            // Jika ditolak, status surat tidak diubah.

            return [
                'http' => 200,
                'body' => [
                    'success' => true,
                    'message' => $validated['status'] === 'diterima'
                        ? 'Penugasan diterima. Status surat menjadi diproses.'
                        : 'Penugasan berhasil ditolak.',
                    'data' => DB::table('tb_penugasan')
                        ->where('id', $id)
                        ->first(),
                ],
            ];
        });

        // Kirim notifikasi hanya jika transaksi dan respons sukses.
        // Penerimanya adalah pembuat penugasan, bukan semua Ketua Tim.
        if (
            $hasil['http'] === 200 &&
            $hasil['body']['success'] === true
        ) {
            $this->kirimNotifikasiPenugasan(
                (int) $id,
                $validated['status']
            );
        }

        return response()->json(
            $hasil['body'],
            $hasil['http']
        );
    }

    /**
     * Menghapus penugasan.
     */
    public function destroy(Request $request, $id)
    {
        $penugasan = DB::table('tb_penugasan')
            ->where('id', $id)
            ->first();

        if (!$penugasan) {
            return response()->json([
                'success' => false,
                'message' => 'Data penugasan tidak ditemukan.',
            ], 404);
        }

        DB::table('tb_penugasan')
            ->where('id', $id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Penugasan berhasil dihapus.',
        ]);
    }

    /**
     * Mengirim notifikasi WhatsApp melalui Fonnte.
     *
     * $jenis:
     * - baru      : dikirim ke pegawai penerima penugasan
     * - diterima  : dikirim ke pembuat penugasan
     * - ditolak   : dikirim ke pembuat penugasan
     *
     * Kegagalan WhatsApp tidak membatalkan transaksi penugasan.
     */
    private function kirimNotifikasiPenugasan(
        int $penugasanId,
        string $jenis
    ): void {
        try {
            $penugasan = DB::table('tb_penugasan')
                ->join(
                    'tb_surat_masuk',
                    'tb_penugasan.surat_masuk_id',
                    '=',
                    'tb_surat_masuk.id'
                )
                ->join(
                    'users as pengirim',
                    'tb_penugasan.ditugaskan_oleh',
                    '=',
                    'pengirim.id'
                )
                ->join(
                    'users as penerima',
                    'tb_penugasan.ditugaskan_kepada',
                    '=',
                    'penerima.id'
                )
                ->select(
                    'tb_penugasan.id',
                    'tb_penugasan.status',
                    'tb_penugasan.catatan_penugasan',
                    'tb_penugasan.catatan_respon',
                    'tb_surat_masuk.nomor_surat',
                    'tb_surat_masuk.perihal',
                    'pengirim.name as nama_pengirim',
                    'pengirim.no_hp as hp_pengirim',
                    'penerima.name as nama_penerima',
                    'penerima.no_hp as hp_penerima'
                )
                ->where('tb_penugasan.id', $penugasanId)
                ->first();

            if (!$penugasan) {
                Log::warning(
                    'Notifikasi penugasan gagal: data tidak ditemukan.',
                    ['penugasan_id' => $penugasanId]
                );

                return;
            }

            // Tentukan satu nomor tujuan berdasarkan jenis kejadian.
            if ($jenis === 'baru') {
                $nomorTujuan = $penugasan->hp_penerima;

                $pesan =
                    "PENUGASAN SURAT MASUK\n\n" .
                    "Yth. {$penugasan->nama_penerima},\n" .
                    "Anda menerima penugasan surat masuk baru.\n\n" .
                    "Nomor surat: {$penugasan->nomor_surat}\n" .
                    "Perihal: {$penugasan->perihal}\n" .
                    "Ditugaskan oleh: {$penugasan->nama_pengirim}\n" .
                    "Status: Menunggu respons";

                if (!empty($penugasan->catatan_penugasan)) {
                    $pesan .=
                        "\nCatatan penugasan: " .
                        $penugasan->catatan_penugasan;
                }
            } elseif (in_array($jenis, ['diterima', 'ditolak'])) {
                $nomorTujuan = $penugasan->hp_pengirim;

                $statusRespons = $jenis === 'diterima'
                    ? 'DITERIMA'
                    : 'DITOLAK';

                $pesan =
                    "PEMBARUAN RESPONS PENUGASAN\n\n" .
                    "Yth. {$penugasan->nama_pengirim},\n" .
                    "Pegawai yang Anda tugaskan telah " .
                    strtolower($statusRespons) . " penugasan.\n\n" .
                    "Nomor surat: {$penugasan->nomor_surat}\n" .
                    "Perihal: {$penugasan->perihal}\n" .
                    "Pegawai: {$penugasan->nama_penerima}\n" .
                    "Respons: {$statusRespons}";

                if (!empty($penugasan->catatan_respon)) {
                    $pesan .=
                        "\nCatatan respons: " .
                        $penugasan->catatan_respon;
                }
            } else {
                Log::warning(
                    'Jenis notifikasi penugasan tidak dikenal.',
                    [
                        'penugasan_id' => $penugasanId,
                        'jenis' => $jenis,
                    ]
                );

                return;
            }

            if (empty($nomorTujuan)) {
                Log::warning(
                    'Notifikasi penugasan tidak dikirim: nomor WhatsApp kosong.',
                    [
                        'penugasan_id' => $penugasanId,
                        'jenis' => $jenis,
                    ]
                );

                return;
            }

            $token = config('services.fonnte.token');
            $url = config(
                'services.fonnte.url',
                'https://api.fonnte.com/send'
            );

            if (empty($token) || empty($url)) {
                Log::error(
                    'Notifikasi penugasan gagal: konfigurasi Fonnte belum lengkap.',
                    [
                        'penugasan_id' => $penugasanId,
                        'jenis' => $jenis,
                    ]
                );

                return;
            }

            // Satu request dengan satu nomor tujuan.
            $response = Http::withHeaders([
                'Authorization' => $token,
            ])
                ->asForm()
                ->timeout(15)
                ->post($url, [
                    'target' => $nomorTujuan,
                    'message' => $pesan,
                ]);

            $hasilFonnte = $response->json();

            if (
                !$response->successful() ||
                (
                    is_array($hasilFonnte) &&
                    array_key_exists('status', $hasilFonnte) &&
                    $hasilFonnte['status'] === false
                )
            ) {
                Log::error(
                    'Pengiriman WhatsApp penugasan gagal.',
                    [
                        'penugasan_id' => $penugasanId,
                        'jenis' => $jenis,
                        'http_status' => $response->status(),
                        'respons_fonnte' => $hasilFonnte,
                    ]
                );

                return;
            }

            Log::info(
                'Permintaan notifikasi WhatsApp penugasan berhasil dikirim ke Fonnte.',
                [
                    'penugasan_id' => $penugasanId,
                    'jenis' => $jenis,
                ]
            );
        } catch (Throwable $e) {
            // Kesalahan Fonnte tidak menggagalkan respons API penugasan.
            Log::error(
                'Terjadi kesalahan saat mengirim WhatsApp penugasan.',
                [
                    'penugasan_id' => $penugasanId,
                    'jenis' => $jenis,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }
}
