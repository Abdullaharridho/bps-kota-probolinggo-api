<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
                'pengirim.name as nama_pengirim',
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
                'pengirim.name as nama_pengirim',
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
     * Membuat penugasan awal atau penugasan ulang.
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
        $suratMasukId = $validated['surat_masuk_id'];

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

            // Ambil penugasan terakhir berdasarkan waktu,
            // dengan ID sebagai urutan tambahan.
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
                // Surat diproses hanya boleh ditugaskan ulang
                // jika penugasan TERAKHIR berstatus ditolak.
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

            $waktuSekarang = now();

            // Selalu buat record baru agar riwayat lama
            // tetap tersimpan.
            $penugasanId = DB::table('tb_penugasan')
                ->insertGetId([
                    'surat_masuk_id' => $suratMasukId,
                    'ditugaskan_oleh' => $user->id,
                    'ditugaskan_kepada' =>
                        $validated['ditugaskan_kepada'],
                    'catatan_penugasan' =>
                        $validated['catatan_penugasan'] ?? null,
                    'status' => 'menunggu_respon',
                    'waktu_penugasan' => $waktuSekarang,
                    'waktu_respon' => null,
                    'catatan_respon' => null,
                    'created_at' => $waktuSekarang,
                    'updated_at' => $waktuSekarang,
                ]);

            // Status baru berubah menjadi diproses pada
            // penugasan awal. Penugasan ulang tidak perlu
            // mengubah status surat lagi.
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

        // 1. Simpan respons pegawai.
        DB::table('tb_penugasan')
            ->where('id', $id)
            ->update([
                'status' => $validated['status'],
                'catatan_respon' =>
                    $validated['catatan_respon'] ?? null,
                'waktu_respon' => $waktuSekarang,
                'updated_at' => $waktuSekarang,
            ]);

        // 2. Jika diterima, ubah status surat menjadi diproses.
        if ($validated['status'] === 'diterima') {
            DB::table('tb_surat_masuk')
                ->where('id', $penugasan->surat_masuk_id)
                ->update([
                    'status' => 'diproses',
                    'updated_at' => $waktuSekarang,
                ]);
        }

        // Jika ditolak, status surat tidak diubah.
        // Penugasan ulang tetap mengikuti alur Ketua Tim.

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
}