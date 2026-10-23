<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class RekomendasiPenugasanController extends Controller
{
    /**
     * Daftar rekomendasi penugasan.
     *
     * Pengusul dapat melihat rekomendasi miliknya.
     * Ketua tim dapat melihat rekomendasi dari anggota timnya.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'required',
                Rule::in([
                    'menunggu_review',
                    'diterima',
                    'ditolak',
                ]),
            ],
        ]);
        $query = DB::table('tb_rekomendasi_penugasan as r')
            ->join(
                'tb_penugasan as p',
                'p.id',
                '=',
                'r.penugasan_id'
            )
            ->join(
                'users as asal',
                'asal.id',
                '=',
                'r.pegawai_asal_id'
            )
            ->join(
                'users as rek',
                'rek.id',
                '=',
                'r.pegawai_rekomendasi_id'
            )
            ->leftJoin(
                'users as pemroses',
                'pemroses.id',
                '=',
                'r.diproses_oleh'
            )
            ->select([
                'r.id',
                'r.penugasan_id',
                'r.pegawai_asal_id',
                'asal.name as nama_pegawai_asal',
                'r.pegawai_rekomendasi_id',
                'rek.name as nama_pegawai_rekomendasi',
                'r.alasan',
                'r.status',
                'r.status_penugasan_sebelumnya',
                'r.diproses_oleh',
                'pemroses.name as nama_pemroses',
                'r.diproses_pada',
                'r.created_at',
                'r.updated_at',
                'p.surat_masuk_id',
                'p.ditugaskan_oleh',
                'p.ditugaskan_kepada',
                'p.catatan_penugasan',
                'p.status as status_penugasan',
            ])
            ->where(function ($q) use ($user) {
                $q->where('r.pegawai_asal_id', $user->id)
                    ->orWhereExists(function ($sub) use ($user) {
                        $sub->selectRaw('1')
                            ->from('tb_anggota_tim as at')
                            ->join(
                                'tb_tim as t',
                                't.id',
                                '=',
                                'at.tim_id'
                            )
                            ->whereColumn(
                                'at.pegawai_id',
                                'r.pegawai_asal_id'
                            )
                            ->where('t.ketua_id', $user->id);
                    });
            });
        if (isset($validated['status'])) {
            $query->where('r.status', $validated['status']);
        }
        $data = $query
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->get();
        return response()->json([
            'success' => true,
            'message' => 'Data rekomendasi penugasan berhasil diambil.',
            'data' => $data,
        ]);
    }

    /**
     * Mengajukan rekomendasi pegawai pengganti.
     *
     * Status penugasan yang diperbolehkan:
     * - menunggu_respon
     * - diterima
     *
     * Setelah rekomendasi dibuat:
     * - Status rekomendasi menjadi menunggu_review.
     * - Status penugasan lama menjadi ditolak.
     * - Status dan respons awal disimpan untuk pemulihan.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'penugasan_id' => [
                'required',
                'integer',
                'exists:tb_penugasan,id',
            ],
            'pegawai_rekomendasi_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'alasan' => [
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
        ], [
            'penugasan_id.required' =>
                'Penugasan wajib dipilih.',
            'penugasan_id.exists' =>
                'Penugasan tidak ditemukan.',
            'pegawai_rekomendasi_id.required' =>
                'Pegawai pengganti wajib dipilih.',
            'pegawai_rekomendasi_id.exists' =>
                'Pegawai pengganti tidak ditemukan.',
            'alasan.required' =>
                'Alasan rekomendasi wajib diisi.',
            'alasan.min' =>
                'Alasan rekomendasi minimal 5 karakter.',
            'alasan.max' =>
                'Alasan rekomendasi maksimal 2000 karakter.',
        ]);
        $user = $request->user();
        $hasil = DB::transaction(function () use (
            $validated,
            $user
        ) {
            $penugasan = DB::table('tb_penugasan')
                ->where('id', $validated['penugasan_id'])
                ->lockForUpdate()
                ->first();
            if (!$penugasan) {
                return [
                    'http' => 404,
                    'body' => [
                        'success' => false,
                        'message' => 'Penugasan tidak ditemukan.',
                    ],
                ];
            }
            // Hanya penerima penugasan yang dapat mengajukan
            // rekomendasi pengganti.
            if (
                (int) $penugasan->ditugaskan_kepada
                !== (int) $user->id
            ) {
                return [
                    'http' => 403,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Anda bukan penerima penugasan ini.',
                    ],
                ];
            }
            // Rekomendasi dapat diajukan untuk kedua status.
            $statusSebelumnya = $penugasan->status;
            if (!in_array(
                $statusSebelumnya,
                ['menunggu_respon', 'diterima'],
                true
            )) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Rekomendasi hanya dapat diajukan untuk ' .
                            'penugasan yang menunggu respons atau sudah diterima.',
                    ],
                ];
            }
            // Pengusul harus terdaftar sebagai anggota tim.
            $anggotaAsal = DB::table('tb_anggota_tim')
                ->where('pegawai_id', $user->id)
                ->first();
            if (!$anggotaAsal) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Anda belum terdaftar sebagai anggota tim.',
                    ],
                ];
            }
            $tim = DB::table('tb_tim')
                ->where('id', $anggotaAsal->tim_id)
                ->first();
            if (!$tim || !$tim->ketua_id) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' => 'Ketua tim belum ditentukan.',
                    ],
                ];
            }
            $pegawaiTujuanId =
                (int) $validated['pegawai_rekomendasi_id'];
            if ($pegawaiTujuanId === (int) $user->id) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Anda tidak dapat merekomendasikan diri sendiri.',
                    ],
                ];
            }
            // Calon pengganti harus anggota tim yang sama.
            $tujuanSatuTim = DB::table('tb_anggota_tim')
                ->where('tim_id', $anggotaAsal->tim_id)
                ->where('pegawai_id', $pegawaiTujuanId)
                ->exists();
            if (!$tujuanSatuTim) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Calon pengganti harus berasal dari tim yang sama.',
                    ],
                ];
            }
            // Pastikan tidak ada rekomendasi aktif lain.
            $sudahAdaPending = DB::table(
                'tb_rekomendasi_penugasan'
            )
                ->where('penugasan_id', $penugasan->id)
                ->where('status', 'menunggu_review')
                ->exists();
            if ($sudahAdaPending) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Penugasan sudah memiliki rekomendasi ' .
                            'yang menunggu review.',
                    ],
                ];
            }
            $waktuSekarang = now();
            // Simpan rekomendasi dan snapshot respons awal.
            $rekomendasiId = DB::table(
                'tb_rekomendasi_penugasan'
            )->insertGetId([
                'penugasan_id' => $penugasan->id,
                'pegawai_asal_id' => $user->id,
                'pegawai_rekomendasi_id' => $pegawaiTujuanId,
                'alasan' => trim($validated['alasan']),
                'status' => 'menunggu_review',
                'status_penugasan_sebelumnya' =>
                    $statusSebelumnya,
                'waktu_respon_sebelumnya' =>
                    $penugasan->waktu_respon,
                'catatan_respon_sebelumnya' =>
                    $penugasan->catatan_respon,
                'diproses_oleh' => null,
                'diproses_pada' => null,
                'created_at' => $waktuSekarang,
                'updated_at' => $waktuSekarang,
            ]);
            // Tutup penugasan lama setelah rekomendasi tercatat.
            DB::table('tb_penugasan')
                ->where('id', $penugasan->id)
                ->update([
                    'status' => 'ditolak',
                    'catatan_respon' =>
                        'Penugasan ditolak karena pengajuan rekomendasi ' .
                        'pegawai pengganti. Rekomendasi #' .
                        $rekomendasiId .
                        ' menunggu keputusan ketua tim.',
                    'waktu_respon' => $waktuSekarang,
                    'updated_at' => $waktuSekarang,
                ]);
            $data = DB::table('tb_rekomendasi_penugasan')
                ->where('id', $rekomendasiId)
                ->first();
            return [
                'http' => 201,
                'body' => [
                    'success' => true,
                    'message' =>
                        'Rekomendasi berhasil dikirim. Status penugasan ' .
                        'berubah menjadi ditolak dan rekomendasi menunggu ' .
                        'keputusan ketua tim.',
                    'data' => $data,
                ],
            ];
        });
        // Kirim ke Ketua Tim hanya setelah rekomendasi tersimpan.
        if (
            $hasil['http'] === 201 &&
            ($hasil['body']['success'] ?? false) === true &&
            isset($hasil['body']['data']->id)
        ) {
            $this->kirimNotifikasiRekomendasi(
                (int) $hasil['body']['data']->id,
                'pengajuan'
            );
        }

        return response()->json(
            $hasil['body'],
            $hasil['http']
        );
    }

    /**
     * Memperbarui rekomendasi yang masih menunggu review.
     * Hanya pengusul yang dapat mengubah rekomendasinya.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'pegawai_rekomendasi_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
            ],
            'alasan' => [
                'sometimes',
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
        ], [
            'pegawai_rekomendasi_id.required' =>
                'Pegawai pengganti wajib dipilih.',
            'alasan.required' =>
                'Alasan rekomendasi wajib diisi.',
            'alasan.min' =>
                'Alasan rekomendasi minimal 5 karakter.',
            'alasan.max' =>
                'Alasan rekomendasi maksimal 2000 karakter.',
        ]);
        if (empty($validated)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada data yang diperbarui.',
            ], 422);
        }
        $user = $request->user();
        $hasil = DB::transaction(function () use (
            $id,
            $validated,
            $user
        ) {
            $rekomendasi = DB::table(
                'tb_rekomendasi_penugasan'
            )
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (!$rekomendasi) {
                return [
                    'http' => 404,
                    'body' => [
                        'success' => false,
                        'message' => 'Rekomendasi tidak ditemukan.',
                    ],
                ];
            }
            if (
                (int) $rekomendasi->pegawai_asal_id
                !== (int) $user->id
            ) {
                return [
                    'http' => 403,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Hanya pengusul yang dapat mengubah rekomendasi.',
                    ],
                ];
            }
            if ($rekomendasi->status !== 'menunggu_review') {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Rekomendasi yang sudah diproses tidak dapat diubah.',
                    ],
                ];
            }
            $penugasan = DB::table('tb_penugasan')
                ->where('id', $rekomendasi->penugasan_id)
                ->lockForUpdate()
                ->first();
            if (
                !$penugasan ||
                (int) $penugasan->ditugaskan_kepada
                    !== (int) $user->id ||
                $penugasan->status !== 'ditolak'
            ) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Penugasan tidak lagi berada dalam status ' .
                            'pengajuan rekomendasi.',
                    ],
                ];
            }
            $dataUpdate = [];
            if (array_key_exists(
                'pegawai_rekomendasi_id',
                $validated
            )) {
                $tujuanId =
                    (int) $validated['pegawai_rekomendasi_id'];
                if ($tujuanId === (int) $user->id) {
                    return [
                        'http' => 422,
                        'body' => [
                            'success' => false,
                            'message' =>
                                'Anda tidak dapat merekomendasikan diri sendiri.',
                        ],
                    ];
                }
                $anggotaAsal = DB::table('tb_anggota_tim')
                    ->where('pegawai_id', $user->id)
                    ->first();
                if (!$anggotaAsal) {
                    return [
                        'http' => 422,
                        'body' => [
                            'success' => false,
                            'message' =>
                                'Anda belum terdaftar sebagai anggota tim.',
                        ],
                    ];
                }
                $tujuanSatuTim = DB::table('tb_anggota_tim')
                    ->where('tim_id', $anggotaAsal->tim_id)
                    ->where('pegawai_id', $tujuanId)
                    ->exists();
                if (!$tujuanSatuTim) {
                    return [
                        'http' => 422,
                        'body' => [
                            'success' => false,
                            'message' =>
                                'Calon pengganti harus berasal dari tim yang sama.',
                        ],
                    ];
                }
                $dataUpdate['pegawai_rekomendasi_id'] = $tujuanId;
            }
            if (array_key_exists('alasan', $validated)) {
                $dataUpdate['alasan'] = trim($validated['alasan']);
            }
            $dataUpdate['updated_at'] = now();
            DB::table('tb_rekomendasi_penugasan')
                ->where('id', $id)
                ->update($dataUpdate);
            return [
                'http' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Rekomendasi berhasil diperbarui.',
                    'data' => DB::table('tb_rekomendasi_penugasan')
                        ->where('id', $id)
                        ->first(),
                ],
            ];
        });
        return response()->json(
            $hasil['body'],
            $hasil['http']
        );
    }

    /**
     * Ketua tim memproses rekomendasi.
     *
     * Jika diterima:
     * - Penugasan lama tetap disimpan sebagai riwayat.
     * - Penugasan baru dibuat untuk calon pengganti.
     * - Rekomendasi berstatus diterima.
     *
     * Jika ditolak:
     * - Rekomendasi berstatus ditolak.
     * - Penugasan lama tetap berstatus ditolak.
     */
    public function proses(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['diterima', 'ditolak']),
            ],
        ], [
            'status.required' =>
                'Keputusan rekomendasi wajib dipilih.',
            'status.in' =>
                'Keputusan harus diterima atau ditolak.',
        ]);
        $user = $request->user();
        $hasil = DB::transaction(function () use (
            $id,
            $validated,
            $user
        ) {
            $rekomendasi = DB::table(
                'tb_rekomendasi_penugasan'
            )
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (!$rekomendasi) {
                return [
                    'http' => 404,
                    'body' => [
                        'success' => false,
                        'message' => 'Rekomendasi tidak ditemukan.',
                    ],
                ];
            }
            if ($rekomendasi->status !== 'menunggu_review') {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' => 'Rekomendasi ini sudah diproses.',
                    ],
                ];
            }
            $penugasan = DB::table('tb_penugasan')
                ->where('id', $rekomendasi->penugasan_id)
                ->lockForUpdate()
                ->first();
            if (!$penugasan) {
                return [
                    'http' => 404,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Penugasan terkait tidak ditemukan.',
                    ],
                ];
            }
            // Pastikan pemroses adalah ketua tim pengusul.
            $anggotaAsal = DB::table('tb_anggota_tim')
                ->where(
                    'pegawai_id',
                    $rekomendasi->pegawai_asal_id
                )
                ->first();
            $tim = $anggotaAsal
                ? DB::table('tb_tim')
                    ->where('id', $anggotaAsal->tim_id)
                    ->first()
                : null;
            if (
                !$tim ||
                (int) $tim->ketua_id !== (int) $user->id
            ) {
                return [
                    'http' => 403,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Hanya ketua tim terkait yang dapat ' .
                            'memproses rekomendasi.',
                    ],
                ];
            }
            // Penugasan lama sudah ditutup saat rekomendasi dibuat.
            if (
                (int) $penugasan->ditugaskan_kepada
                    !== (int) $rekomendasi->pegawai_asal_id ||
                $penugasan->status !== 'ditolak'
            ) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Penugasan sudah berubah atau tidak lagi ' .
                            'berada dalam proses rekomendasi.',
                    ],
                ];
            }
            $waktuSekarang = now();
            if ($validated['status'] === 'diterima') {
                $tujuanId =
                    (int) $rekomendasi->pegawai_rekomendasi_id;
                // Pastikan calon pengganti masih anggota tim yang sama.
                $tujuanSatuTim = DB::table('tb_anggota_tim')
                    ->where('tim_id', $tim->id)
                    ->where('pegawai_id', $tujuanId)
                    ->exists();
                if (!$tujuanSatuTim) {
                    return [
                        'http' => 422,
                        'body' => [
                            'success' => false,
                            'message' =>
                                'Calon pengganti tidak lagi menjadi ' .
                                'anggota tim yang sama.',
                        ],
                    ];
                }
                // Perbarui catatan riwayat penugasan lama.
                DB::table('tb_penugasan')
                    ->where('id', $penugasan->id)
                    ->update([
                        'catatan_respon' =>
                            'Penugasan dialihkan setelah rekomendasi #' .
                            $rekomendasi->id .
                            ' disetujui ketua tim.',
                        'waktu_respon' => $waktuSekarang,
                        'updated_at' => $waktuSekarang,
                    ]);
                // Buat baris penugasan baru agar riwayat lama tetap ada.
                $penugasanBaruId = DB::table('tb_penugasan')
                    ->insertGetId([
                        'surat_masuk_id' =>
                            $penugasan->surat_masuk_id,
                        'ditugaskan_oleh' =>
                            $penugasan->ditugaskan_oleh,
                        'ditugaskan_kepada' => $tujuanId,
                        'catatan_penugasan' =>
                            $penugasan->catatan_penugasan,
                        'status' => 'menunggu_respon',
                        'waktu_penugasan' => $waktuSekarang,
                        'waktu_respon' => null,
                        'catatan_respon' => null,
                        'created_at' => $waktuSekarang,
                        'updated_at' => $waktuSekarang,
                    ]);
                DB::table('tb_rekomendasi_penugasan')
                    ->where('id', $rekomendasi->id)
                    ->update([
                        'status' => 'diterima',
                        'diproses_oleh' => $user->id,
                        'diproses_pada' => $waktuSekarang,
                        'updated_at' => $waktuSekarang,
                    ]);
                return [
                    'http' => 200,
                    'body' => [
                        'success' => true,
                        'message' =>
                            'Rekomendasi disetujui dan penugasan baru ' .
                            'berhasil dibuat.',
                        'data' => [
                            'rekomendasi_id' =>
                                (int) $rekomendasi->id,
                            'penugasan_lama_id' =>
                                (int) $penugasan->id,
                            'penugasan_baru_id' =>
                                (int) $penugasanBaruId,
                            'pegawai_sebelumnya_id' =>
                                (int) $rekomendasi->pegawai_asal_id,
                            'pegawai_baru_id' => $tujuanId,
                            'status_rekomendasi' => 'diterima',
                            'status_penugasan_lama' => 'ditolak',
                            'status_penugasan_baru' => 'menunggu_respon',
                        ],
                    ],
                ];
            }
            // Ketua tim menolak rekomendasi.
            // Penugasan lama tetap ditolak.
            DB::table('tb_rekomendasi_penugasan')
                ->where('id', $rekomendasi->id)
                ->update([
                    'status' => 'ditolak',
                    'diproses_oleh' => $user->id,
                    'diproses_pada' => $waktuSekarang,
                    'updated_at' => $waktuSekarang,
                ]);
            return [
                'http' => 200,
                'body' => [
                    'success' => true,
                    'message' =>
                        'Rekomendasi ditolak. Penugasan lama tetap ditolak.',
                    'data' => [
                        'rekomendasi_id' =>
                            (int) $rekomendasi->id,
                        'status_rekomendasi' => 'ditolak',
                        'status_penugasan' => 'ditolak',
                    ],
                ],
            ];
        });
        // Keputusan sudah tersimpan. Kirim notifikasi sesuai hasilnya.
        if (
            $hasil['http'] === 200 &&
            ($hasil['body']['success'] ?? false) === true
        ) {
            $this->kirimNotifikasiRekomendasi(
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
     * Menghapus rekomendasi yang masih menunggu review.
     * Status dan respons penugasan sebelumnya dipulihkan.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $hasil = DB::transaction(function () use ($id, $user) {
            $rekomendasi = DB::table(
                'tb_rekomendasi_penugasan'
            )
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (!$rekomendasi) {
                return [
                    'http' => 404,
                    'body' => [
                        'success' => false,
                        'message' => 'Rekomendasi tidak ditemukan.',
                    ],
                ];
            }
            if (
                (int) $rekomendasi->pegawai_asal_id
                !== (int) $user->id
            ) {
                return [
                    'http' => 403,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Hanya pengusul yang dapat menghapus rekomendasi.',
                    ],
                ];
            }
            if ($rekomendasi->status !== 'menunggu_review') {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Rekomendasi yang sudah diproses tidak dapat dihapus.',
                    ],
                ];
            }
            $penugasan = DB::table('tb_penugasan')
                ->where('id', $rekomendasi->penugasan_id)
                ->lockForUpdate()
                ->first();
            if (
                !$penugasan ||
                (int) $penugasan->ditugaskan_kepada
                    !== (int) $user->id ||
                $penugasan->status !== 'ditolak'
            ) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Penugasan sudah berubah. Rekomendasi tidak dapat dihapus.',
                    ],
                ];
            }
            $statusSebelumnya =
                $rekomendasi->status_penugasan_sebelumnya;
            if (!in_array(
                $statusSebelumnya,
                ['menunggu_respon', 'diterima'],
                true
            )) {
                return [
                    'http' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Status awal penugasan tidak ditemukan. ' .
                            'Rekomendasi tidak dapat dihapus.',
                    ],
                ];
            }
            // Pulihkan status dan respons persis seperti sebelumnya.
            DB::table('tb_penugasan')
                ->where('id', $penugasan->id)
                ->update([
                    'status' => $statusSebelumnya,
                    'waktu_respon' =>
                        $rekomendasi->waktu_respon_sebelumnya,
                    'catatan_respon' =>
                        $rekomendasi->catatan_respon_sebelumnya,
                    'updated_at' => now(),
                ]);
            DB::table('tb_rekomendasi_penugasan')
                ->where('id', $id)
                ->delete();
            return [
                'http' => 200,
                'body' => [
                    'success' => true,
                    'message' =>
                        'Rekomendasi berhasil dihapus dan status ' .
                        'penugasan sebelumnya dipulihkan.',
                ],
            ];
        });
        return response()->json(
            $hasil['body'],
            $hasil['http']
        );
    }

    /**
     * Mengirim notifikasi rekomendasi melalui Fonnte.
     * pengajuan: Ketua Tim; diterima: pengusul dan calon pengganti;
     * ditolak: pengusul. Setiap nomor dikirimi secara individual.
     */
    private function kirimNotifikasiRekomendasi(int $rekomendasiId, string $jenis): void
    {
        try {
            $r = DB::table('tb_rekomendasi_penugasan as r')
                ->join('tb_penugasan as p', 'p.id', '=', 'r.penugasan_id')
                ->join('tb_surat_masuk as s', 's.id', '=', 'p.surat_masuk_id')
                ->join('users as asal', 'asal.id', '=', 'r.pegawai_asal_id')
                ->join('users as rek', 'rek.id', '=', 'r.pegawai_rekomendasi_id')
                ->select([
                    'r.id', 'r.pegawai_asal_id', 'r.pegawai_rekomendasi_id',
                    'r.alasan', 'asal.name as nama_asal', 'asal.no_hp as hp_asal',
                    'rek.name as nama_rekomendasi', 'rek.no_hp as hp_rekomendasi',
                    's.nomor_surat', 's.perihal',
                ])
                ->where('r.id', $rekomendasiId)
                ->first();

            if (!$r) {
                Log::warning('Data rekomendasi untuk notifikasi tidak ditemukan.', [
                    'rekomendasi_id' => $rekomendasiId,
                    'jenis' => $jenis,
                ]);
                return;
            }

            $targets = [];

            if ($jenis === 'pengajuan') {
                $anggota = DB::table('tb_anggota_tim')
                    ->where('pegawai_id', $r->pegawai_asal_id)
                    ->first();
                $tim = $anggota
                    ? DB::table('tb_tim')->where('id', $anggota->tim_id)->first()
                    : null;
                $ketua = ($tim && $tim->ketua_id)
                    ? DB::table('users')->where('id', $tim->ketua_id)->first()
                    : null;

                if ($ketua) {
                    $targets[] = [
                        'nomor' => $ketua->no_hp ?? null,
                        'pesan' =>
                            "REKOMENDASI PENUGASAN BARU\n\n" .
                            "Yth. {$ketua->name},\n" .
                            "Ada rekomendasi pegawai pengganti yang perlu ditinjau.\n\n" .
                            "Nomor surat: {$r->nomor_surat}\n" .
                            "Perihal: {$r->perihal}\n" .
                            "Pengusul: {$r->nama_asal}\n" .
                            "Calon pengganti: {$r->nama_rekomendasi}\n" .
                            "Alasan: {$r->alasan}\nStatus: Menunggu review",
                    ];
                }
            } elseif ($jenis === 'diterima') {
                $targets[] = [
                    'nomor' => $r->hp_asal,
                    'pesan' =>
                        "REKOMENDASI PENUGASAN DISETUJUI\n\n" .
                        "Yth. {$r->nama_asal}, rekomendasi pengganti Anda telah disetujui.\n\n" .
                        "Nomor surat: {$r->nomor_surat}\n" .
                        "Perihal: {$r->perihal}\n" .
                        "Pegawai pengganti: {$r->nama_rekomendasi}\nStatus: Diterima",
                ];
                $targets[] = [
                    'nomor' => $r->hp_rekomendasi,
                    'pesan' =>
                        "PENUGASAN BARU UNTUK ANDA\n\n" .
                        "Yth. {$r->nama_rekomendasi}, Anda ditetapkan sebagai pegawai pengganti.\n\n" .
                        "Nomor surat: {$r->nomor_surat}\n" .
                        "Perihal: {$r->perihal}\nStatus penugasan: Menunggu respons",
                ];
            } elseif ($jenis === 'ditolak') {
                $targets[] = [
                    'nomor' => $r->hp_asal,
                    'pesan' =>
                        "REKOMENDASI PENUGASAN DITOLAK\n\n" .
                        "Yth. {$r->nama_asal}, rekomendasi pengganti Anda ditolak oleh Ketua Tim.\n\n" .
                        "Nomor surat: {$r->nomor_surat}\n" .
                        "Perihal: {$r->perihal}\n" .
                        "Calon pengganti: {$r->nama_rekomendasi}\nStatus: Ditolak",
                ];
            } else {
                Log::warning('Jenis notifikasi rekomendasi tidak dikenal.', [
                    'rekomendasi_id' => $rekomendasiId,
                    'jenis' => $jenis,
                ]);
                return;
            }

            $token = config('services.fonnte.token');
            $url = config('services.fonnte.url', 'https://api.fonnte.com/send');
            if (empty($token) || empty($url)) {
                Log::error('Konfigurasi Fonnte belum lengkap.', [
                    'rekomendasi_id' => $rekomendasiId,
                    'jenis' => $jenis,
                ]);
                return;
            }

            foreach ($targets as $target) {
                if (empty($target['nomor'])) {
                    Log::warning('Nomor WhatsApp kosong; notifikasi dilewati.', [
                        'rekomendasi_id' => $rekomendasiId,
                        'jenis' => $jenis,
                    ]);
                    continue;
                }

                $response = Http::withHeaders(['Authorization' => $token])
                    ->asForm()
                    ->timeout(15)
                    ->post($url, [
                        'target' => $target['nomor'],
                        'message' => $target['pesan'],
                    ]);
                $body = $response->json();

                if (
                    !$response->successful() ||
                    (is_array($body) && array_key_exists('status', $body) && $body['status'] === false)
                ) {
                    Log::error('Pengiriman WhatsApp rekomendasi gagal.', [
                        'rekomendasi_id' => $rekomendasiId,
                        'jenis' => $jenis,
                        'http_status' => $response->status(),
                        'respons_fonnte' => $body,
                    ]);
                    continue;
                }

                Log::info('Permintaan WhatsApp rekomendasi dikirim ke Fonnte.', [
                    'rekomendasi_id' => $rekomendasiId,
                    'jenis' => $jenis,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Kesalahan saat mengirim WhatsApp rekomendasi.', [
                'rekomendasi_id' => $rekomendasiId,
                'jenis' => $jenis,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function pegawaiPengganti($id)
{
    $penugasan = DB::table('tb_penugasan')
        ->where('id', $id)
        ->first();
    if (!$penugasan) {
        return response()->json([
            'success' => false,
            'message' => 'Data penugasan tidak ditemukan.',
            'data' => []
        ], 404);
    }
    $pegawaiId = (int) $penugasan->ditugaskan_kepada;
    // Cari tim tempat pegawai menjadi anggota.
    $timIds = DB::table('tb_anggota_tim')
        ->where('pegawai_id', $pegawaiId)
        ->pluck('tim_id');
    // Jika pegawai merupakan ketua tim, cari tim yang dipimpinnya.
    if ($timIds->isEmpty()) {
        $timIds = DB::table('tb_tim')
            ->where('ketua_id', $pegawaiId)
            ->pluck('id');
    }
    if ($timIds->isEmpty()) {
        return response()->json([
            'success' => true,
            'message' => 'Pegawai penerima belum terhubung dengan tim.',
            'data' => []
        ]);
    }
    $pegawai = DB::table('tb_anggota_tim')
        ->join(
            'users',
            'tb_anggota_tim.pegawai_id',
            '=',
            'users.id'
        )
        ->whereIn('tb_anggota_tim.tim_id', $timIds)
        ->where('users.role', 'pegawai')
        ->where('users.id', '!=', $pegawaiId)
        ->select(
            'users.id',
            'users.name',
            'users.role'
        )
        ->orderBy('users.name')
        ->get();
    return response()->json([
        'success' => true,
        'message' => 'Daftar calon pengganti berhasil diambil.',
        'data' => $pegawai
    ]);
}
}