<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PenugasanController extends Controller
{
    /**
     * ======================================================
     * DAFTAR PENUGASAN
     * ======================================================
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $role = $user->role ?? '';

            $query = DB::table('tb_penugasan as p')
                ->join('tb_kegiatan as k', 'k.id', '=', 'p.kegiatan_id')
                ->join('tb_tim as t', 't.id', '=', 'k.tim_id')
                ->join('users as penerima', 'penerima.id', '=', 'p.ditugaskan_kepada')
                ->join('users as pemberi', 'pemberi.id', '=', 'p.ditugaskan_oleh')
                ->select(
                    'p.id',
                    'p.kegiatan_id',
                    'p.ditugaskan_oleh',
                    'p.ditugaskan_kepada',
                    'p.status',
                    'p.catatan_penugasan',
                    'p.waktu_penugasan',
                    'p.created_at',
                    'p.updated_at',

                    'k.nama_kegiatan',
                    'k.uraian_kegiatan',
                    'k.lokasi',
                    'k.tanggal_mulai',
                    'k.tanggal_selesai',
                    'k.volume_jam',
                    'k.jam_mulai',
                    'k.jam_selesai',
                    'k.status as status_kegiatan',

                    't.nama_tim',

                    'penerima.name as nama_pegawai',
                    'pemberi.name as nama_pemberi_tugas'
                );

            if ($role === 'ketua_tim') {
                $query->where('t.ketua_id', $user->id);

            } elseif ($role === 'pegawai') {
                $query->where('p.ditugaskan_kepada', $user->id);

            } elseif (!in_array($role, [
                'super_admin',
                'pimpinan',
                'operator'
            ], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat penugasan.'
                ], 403);
            }

            if ($request->filled('kegiatan_id')) {
                $query->where('p.kegiatan_id', $request->kegiatan_id);
            }

            if ($request->filled('status')) {
                $query->where('p.status', $request->status);
            }

            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('k.nama_kegiatan', 'like', "%{$search}%")
                        ->orWhere('k.lokasi', 'like', "%{$search}%")
                        ->orWhere('t.nama_tim', 'like', "%{$search}%")
                        ->orWhere('penerima.name', 'like', "%{$search}%");
                });
            }

            $data = $query
                ->orderBy('p.created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Daftar penugasan berhasil diambil.',
                'data' => $data
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data penugasan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * DAFTAR PEGAWAI YANG TERSEDIA
     * ======================================================
     *
     * Digunakan untuk mengisi pilihan pegawai ketika
     * Ketua Tim membuat atau mengubah penugasan.
     *
     * Parameter:
     * - kegiatan_id
     *
     * Pegawai yang sudah ditugaskan pada kegiatan yang sama
     * atau memiliki jadwal bentrok tidak akan ditampilkan.
     */
    public function pegawaiTersedia(Request $request)
    {
        try {
            $user = $request->user();

            if (($user->role ?? '') !== 'ketua_tim') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya Ketua Tim yang dapat melihat daftar pegawai tersedia.'
                ], 403);
            }

            $validator = Validator::make(
                $request->all(),
                [
                    'kegiatan_id' => [
                        'required',
                        'integer',
                        'exists:tb_kegiatan,id'
                    ]
                ],
                [
                    'kegiatan_id.required' => 'Kegiatan wajib dipilih.',
                    'kegiatan_id.integer' => 'ID kegiatan tidak valid.',
                    'kegiatan_id.exists' => 'Kegiatan tidak ditemukan.'
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak valid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $kegiatan = DB::table('tb_kegiatan')
                ->where('id', $request->kegiatan_id)
                ->first();

            if (!$kegiatan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kegiatan tidak ditemukan.'
                ], 404);
            }

            $tim = DB::table('tb_tim')
                ->where('id', $kegiatan->tim_id)
                ->first();

            if (!$tim || (int) $tim->ketua_id !== (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda hanya dapat memilih pegawai dari kegiatan tim yang Anda pimpin.'
                ], 403);
            }

            $anggotaTim = DB::table('tb_anggota_tim as a')
                ->join('users as u', 'u.id', '=', 'a.pegawai_id')
                ->where('a.tim_id', $kegiatan->tim_id)
                ->select(
                    'u.id',
                    'u.name'
                )
                ->orderBy('u.name')
                ->get();

            $pegawaiTersedia = $anggotaTim->filter(function ($pegawai) use ($kegiatan) {

                // Jangan tampilkan pegawai yang sudah ditugaskan
                // pada kegiatan yang sedang dipilih.
                if ($this->pegawaiSudahDitugaskanUntukKegiatan(
                    $pegawai->id,
                    $kegiatan->id
                )) {
                    return false;
                }

                // Jangan tampilkan pegawai yang jadwalnya bentrok.
                return !$this->pegawaiMemilikiBentrok(
                    $pegawai->id,
                    $kegiatan
                );

            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Daftar pegawai tersedia berhasil diambil.',
                'data' => $pegawaiTersedia
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar pegawai tersedia.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * DETAIL PENUGASAN
     * ======================================================
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            $penugasan = $this->getDetailPenugasan($id);

            if (!$penugasan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data penugasan tidak ditemukan.'
                ], 404);
            }

            if (!$this->bolehMelihatPenugasan($user, $penugasan)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat penugasan ini.'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail penugasan berhasil diambil.',
                'data' => $penugasan
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil detail penugasan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * TAMBAH PENUGASAN
     * ======================================================
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? '') !== 'ketua_tim') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Ketua Tim yang dapat membuat penugasan.'
            ], 403);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'kegiatan_id' => [
                    'required',
                    'integer',
                    'exists:tb_kegiatan,id'
                ],

                'ditugaskan_kepada' => [
                    'required',
                    'integer',
                    'exists:users,id'
                ],

                'catatan_penugasan' => [
                    'nullable',
                    'string'
                ]
            ],
            [
                'kegiatan_id.required' => 'Kegiatan wajib dipilih.',
                'kegiatan_id.integer' => 'ID kegiatan tidak valid.',
                'kegiatan_id.exists' => 'Kegiatan tidak ditemukan.',

                'ditugaskan_kepada.required' => 'Pegawai yang ditugaskan wajib dipilih.',
                'ditugaskan_kepada.integer' => 'ID pegawai tidak valid.',
                'ditugaskan_kepada.exists' => 'Pegawai tidak ditemukan.',

                'catatan_penugasan.string' => 'Catatan penugasan harus berupa teks.'
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data penugasan tidak valid.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $validated = $validator->validated();

            $penugasanId = DB::transaction(function () use (
                $validated,
                $user
            ) {

                $kegiatan = DB::table('tb_kegiatan')
                    ->where('id', $validated['kegiatan_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$kegiatan) {
                    throw new \RuntimeException('KEGIATAN_TIDAK_DITEMUKAN');
                }

                $tim = DB::table('tb_tim')
                    ->where('id', $kegiatan->tim_id)
                    ->first();

                if (!$tim || (int) $tim->ketua_id !== (int) $user->id) {
                    throw new \RuntimeException('BUKAN_KETUA_TIM');
                }

                $anggota = DB::table('tb_anggota_tim')
                    ->where('tim_id', $kegiatan->tim_id)
                    ->where('pegawai_id', $validated['ditugaskan_kepada'])
                    ->exists();

                if (!$anggota) {
                    throw new \RuntimeException('BUKAN_ANGGOTA_TIM');
                }

                // Kunci baris pegawai agar pemeriksaan penugasan
                // lebih aman ketika ada request bersamaan.
                $pegawai = DB::table('users')
                    ->where('id', $validated['ditugaskan_kepada'])
                    ->lockForUpdate()
                    ->first();

                if (!$pegawai) {
                    throw new \RuntimeException('PEGAWAI_TIDAK_DITEMUKAN');
                }

                $sudahDitugaskan = $this->pegawaiSudahDitugaskanUntukKegiatan(
                    $pegawai->id,
                    $kegiatan->id
                );

                if ($sudahDitugaskan) {
                    throw new \RuntimeException('PENUGASAN_SUDAH_ADA');
                }

                // Pemeriksaan bentrok jadwal.
                if ($this->pegawaiMemilikiBentrok(
                    $pegawai->id,
                    $kegiatan
                )) {
                    throw new \RuntimeException('JADWAL_PEGAWAI_BENTROK');
                }

                $now = now();

                $id = DB::table('tb_penugasan')->insertGetId([
                    'kegiatan_id' => $kegiatan->id,
                    'ditugaskan_oleh' => $user->id,
                    'ditugaskan_kepada' => $validated['ditugaskan_kepada'],
                    'status' => 'proses',
                    'catatan_penugasan' => $validated['catatan_penugasan'] ?? null,
                    'waktu_penugasan' => $now,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);

                DB::table('tb_kegiatan')
                    ->where('id', $kegiatan->id)
                    ->update([
                        'status' => 'proses',
                        'updated_at' => $now
                    ]);

                return $id;
            });

            $data = $this->getDetailPenugasan($penugasanId);

            return response()->json([
                'success' => true,
                'message' => 'Penugasan berhasil dibuat.',
                'data' => $data
            ], 201);

        } catch (\RuntimeException $e) {

            $messages = [
                'KEGIATAN_TIDAK_DITEMUKAN' => [
                    'Kegiatan tidak ditemukan.',
                    404
                ],
                'BUKAN_KETUA_TIM' => [
                    'Anda hanya dapat memberikan penugasan untuk kegiatan tim yang Anda pimpin.',
                    403
                ],
                'BUKAN_ANGGOTA_TIM' => [
                    'Pegawai yang dipilih bukan anggota tim kegiatan tersebut.',
                    422
                ],
                'PEGAWAI_TIDAK_DITEMUKAN' => [
                    'Pegawai tidak ditemukan.',
                    404
                ],
                'PENUGASAN_SUDAH_ADA' => [
                    'Pegawai tersebut sudah mendapatkan penugasan untuk kegiatan ini.',
                    409
                ],
                'JADWAL_PEGAWAI_BENTROK' => [
                    'Pegawai tersebut sudah memiliki penugasan pada tanggal atau jam yang bertabrakan.',
                    409
                ]
            ];

            if (isset($messages[$e->getMessage()])) {
                return response()->json([
                    'success' => false,
                    'message' => $messages[$e->getMessage()][0]
                ], $messages[$e->getMessage()][1]);
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat membuat penugasan.'
            ], 500);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat membuat penugasan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * UPDATE PENUGASAN
     * ======================================================
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();

            $penugasan = DB::table('tb_penugasan as p')
                ->join('tb_kegiatan as k', 'k.id', '=', 'p.kegiatan_id')
                ->join('tb_tim as t', 't.id', '=', 'k.tim_id')
                ->where('p.id', $id)
                ->select(
                    'p.*',
                    'k.tim_id',
                    'k.tanggal_mulai',
                    'k.tanggal_selesai',
                    'k.volume_jam',
                    'k.jam_mulai',
                    'k.jam_selesai',
                    't.ketua_id'
                )
                ->first();

            if (!$penugasan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data penugasan tidak ditemukan.'
                ], 404);
            }

            if (
                ($user->role ?? '') !== 'ketua_tim' ||
                (int) $penugasan->ketua_id !== (int) $user->id
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah penugasan ini.'
                ], 403);
            }

            $validator = Validator::make(
                $request->all(),
                [
                    'ditugaskan_kepada' => [
                        'sometimes',
                        'required',
                        'integer',
                        'exists:users,id'
                    ],

                    'catatan_penugasan' => [
                        'sometimes',
                        'nullable',
                        'string'
                    ]
                ],
                [
                    'ditugaskan_kepada.required' => 'Pegawai yang ditugaskan wajib dipilih.',
                    'ditugaskan_kepada.integer' => 'ID pegawai tidak valid.',
                    'ditugaskan_kepada.exists' => 'Pegawai tidak ditemukan.',

                    'catatan_penugasan.string' => 'Catatan penugasan harus berupa teks.'
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data penugasan tidak valid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            if (empty($validated)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data yang diperbarui.'
                ], 422);
            }

            if (array_key_exists('ditugaskan_kepada', $validated)) {

                $memilikiLaporan = DB::table('tb_laporan_kegiatan')
                    ->where('penugasan_id', $id)
                    ->exists();

                if ($memilikiLaporan) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Penerima penugasan tidak dapat diganti karena sudah memiliki laporan.'
                    ], 409);
                }

                $anggota = DB::table('tb_anggota_tim')
                    ->where('tim_id', $penugasan->tim_id)
                    ->where('pegawai_id', $validated['ditugaskan_kepada'])
                    ->exists();

                if (!$anggota) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Pegawai yang dipilih bukan anggota tim kegiatan tersebut.'
                    ], 422);
                }

                $duplikat = DB::table('tb_penugasan')
                    ->where('kegiatan_id', $penugasan->kegiatan_id)
                    ->where('ditugaskan_kepada', $validated['ditugaskan_kepada'])
                    ->where('id', '!=', $id)
                    ->exists();

                if ($duplikat) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Pegawai tersebut sudah mendapatkan penugasan untuk kegiatan ini.'
                    ], 409);
                }

                $pegawai = DB::table('users')
                    ->where('id', $validated['ditugaskan_kepada'])
                    ->first();

                if (!$pegawai) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Pegawai tidak ditemukan.'
                    ], 404);
                }

                // Bentuk kegiatan yang sedang diedit.
                $kegiatanSaatIni = (object) [
                    'id' => $penugasan->kegiatan_id,
                    'tanggal_mulai' => $penugasan->tanggal_mulai,
                    'tanggal_selesai' => $penugasan->tanggal_selesai,
                    'volume_jam' => $penugasan->volume_jam,
                    'jam_mulai' => $penugasan->jam_mulai,
                    'jam_selesai' => $penugasan->jam_selesai
                ];

                // Abaikan penugasan yang sedang diedit.
                if ($this->pegawaiMemilikiBentrok(
                    $pegawai->id,
                    $kegiatanSaatIni,
                    $id
                )) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Pegawai tersebut sudah memiliki penugasan pada tanggal atau jam yang bertabrakan.'
                    ], 409);
                }
            }

            $update = [];

            if (array_key_exists('ditugaskan_kepada', $validated)) {
                $update['ditugaskan_kepada'] = $validated['ditugaskan_kepada'];
            }

            if (array_key_exists('catatan_penugasan', $validated)) {
                $update['catatan_penugasan'] = $validated['catatan_penugasan'];
            }

            $update['updated_at'] = now();

            DB::table('tb_penugasan')
                ->where('id', $id)
                ->update($update);

            return response()->json([
                'success' => true,
                'message' => 'Penugasan berhasil diperbarui.',
                'data' => $this->getDetailPenugasan($id)
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui penugasan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * HAPUS PENUGASAN
     * ======================================================
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();

            $penugasan = DB::table('tb_penugasan as p')
                ->join('tb_kegiatan as k', 'k.id', '=', 'p.kegiatan_id')
                ->join('tb_tim as t', 't.id', '=', 'k.tim_id')
                ->where('p.id', $id)
                ->select(
                    'p.id',
                    'p.kegiatan_id',
                    't.ketua_id'
                )
                ->first();

            if (!$penugasan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data penugasan tidak ditemukan.'
                ], 404);
            }

            if (
                ($user->role ?? '') !== 'ketua_tim' ||
                (int) $penugasan->ketua_id !== (int) $user->id
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus penugasan ini.'
                ], 403);
            }

            $memilikiLaporan = DB::table('tb_laporan_kegiatan')
                ->where('penugasan_id', $id)
                ->exists();

            if ($memilikiLaporan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penugasan tidak dapat dihapus karena sudah memiliki laporan kegiatan.'
                ], 409);
            }

            DB::transaction(function () use ($id, $penugasan) {

                DB::table('tb_penugasan')
                    ->where('id', $id)
                    ->delete();

                $sisaPenugasan = DB::table('tb_penugasan')
                    ->where('kegiatan_id', $penugasan->kegiatan_id)
                    ->get(['status']);

                if ($sisaPenugasan->isEmpty()) {
                    $statusKegiatan = 'baru';

                } elseif ($sisaPenugasan->contains(function ($item) {
                    return $item->status !== 'selesai';
                })) {
                    $statusKegiatan = 'proses';

                } else {
                    $statusKegiatan = 'selesai';
                }

                DB::table('tb_kegiatan')
                    ->where('id', $penugasan->kegiatan_id)
                    ->update([
                        'status' => $statusKegiatan,
                        'updated_at' => now()
                    ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Penugasan berhasil dihapus.'
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus penugasan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * CEK APAKAH PEGAWAI SUDAH DITUGASKAN PADA KEGIATAN
     * ======================================================
     *
     * Pemeriksaan ini tetap berlaku untuk semua status,
     * agar satu pegawai tidak mendapat penugasan ganda
     * pada kegiatan yang sama.
     */
    private function pegawaiSudahDitugaskanUntukKegiatan(
        $pegawaiId,
        $kegiatanId
    ): bool {
        return DB::table('tb_penugasan')
            ->where('kegiatan_id', $kegiatanId)
            ->where('ditugaskan_kepada', $pegawaiId)
            ->exists();
    }


    /**
     * ======================================================
     * PEMERIKSAAN BENTROK JADWAL PEGAWAI
     * ======================================================
     *
     * Aturan:
     *
     * 1. Hanya penugasan berstatus proses yang diperiksa.
     *
     * 2. Jika kegiatan baru memiliki volume >= 8 jam,
     *    setiap penugasan aktif pada tanggal beririsan
     *    dianggap bentrok.
     *
     * 3. Jika kegiatan baru memiliki volume < 8 jam:
     *    - Penugasan lama dengan volume >= 8 jam
     *      dianggap satu hari penuh.
     *    - Penugasan lama dengan volume < 8 jam
     *      diperiksa berdasarkan irisan jam.
     *
     * 4. Jika jam tidak tersedia, pemeriksaan dilakukan
     *    secara konservatif sebagai satu hari penuh.
     *
     * 5. $excludePenugasanId digunakan saat update agar
     *    penugasan yang sedang diedit tidak menghitung
     *    dirinya sendiri sebagai bentrok.
     */
    private function pegawaiMemilikiBentrok(
        $pegawaiId,
        $kegiatan,
        $excludePenugasanId = null
    ): bool {

        $volumeBaru = (float) ($kegiatan->volume_jam ?? 0);

        $jamBaruTidakLengkap =
            empty($kegiatan->jam_mulai) ||
            empty($kegiatan->jam_selesai);

        $query = DB::table('tb_penugasan as p')
            ->join('tb_kegiatan as k', 'k.id', '=', 'p.kegiatan_id')
            ->where('p.ditugaskan_kepada', $pegawaiId)
            ->where('p.status', 'proses')

            // Tanggal kegiatan harus beririsan.
            ->where('k.tanggal_mulai', '<=', $kegiatan->tanggal_selesai)
            ->where('k.tanggal_selesai', '>=', $kegiatan->tanggal_mulai);

        if ($excludePenugasanId !== null) {
            $query->where('p.id', '!=', $excludePenugasanId);
        }

        // Kegiatan baru 8 jam atau lebih:
        // setiap jadwal aktif pada tanggal yang beririsan bentrok.
        if ($volumeBaru >= 8 || $jamBaruTidakLengkap) {
            return $query->exists();
        }

        $query->where(function ($q) use ($kegiatan) {

            // Penugasan lama 8 jam atau lebih dianggap
            // menggunakan waktu satu hari penuh.
            $q->where('k.volume_jam', '>=', 8)

                // Data jam yang tidak lengkap dianggap penuh
                // agar tidak terjadi penugasan yang berisiko.
                ->orWhereNull('k.jam_mulai')
                ->orWhereNull('k.jam_selesai')

                // Jika kedua kegiatan kurang dari 8 jam,
                // periksa irisan jam.
                ->orWhere(function ($jam) use ($kegiatan) {
                    $jam->where('k.volume_jam', '<', 8)
                        ->whereNotNull('k.jam_mulai')
                        ->whereNotNull('k.jam_selesai')
                        ->where('k.jam_mulai', '<', $kegiatan->jam_selesai)
                        ->where('k.jam_selesai', '>', $kegiatan->jam_mulai);
                });
        });

        return $query->exists();
    }


    /**
     * ======================================================
     * DETAIL PENUGASAN DENGAN INFORMASI KEGIATAN DAN TIM
     * ======================================================
     */
    private function getDetailPenugasan($id)
    {
        return DB::table('tb_penugasan as p')
            ->join('tb_kegiatan as k', 'k.id', '=', 'p.kegiatan_id')
            ->join('tb_tim as t', 't.id', '=', 'k.tim_id')
            ->join('users as penerima', 'penerima.id', '=', 'p.ditugaskan_kepada')
            ->join('users as pemberi', 'pemberi.id', '=', 'p.ditugaskan_oleh')
            ->where('p.id', $id)
            ->select(
                'p.id',
                'p.kegiatan_id',
                'p.ditugaskan_oleh',
                'p.ditugaskan_kepada',
                'p.status',
                'p.catatan_penugasan',
                'p.waktu_penugasan',
                'p.created_at',
                'p.updated_at',

                'k.nama_kegiatan',
                'k.uraian_kegiatan',
                'k.lokasi',
                'k.tanggal_mulai',
                'k.tanggal_selesai',
                'k.volume_jam',
                'k.jam_mulai',
                'k.jam_selesai',
                'k.status as status_kegiatan',

                't.nama_tim',

                'penerima.name as nama_pegawai',
                'pemberi.name as nama_pemberi_tugas'
            )
            ->first();
    }


    /**
     * ======================================================
     * PEMERIKSAAN HAK AKSES DETAIL PENUGASAN
     * ======================================================
     */
    private function bolehMelihatPenugasan($user, $penugasan): bool
    {
        $role = $user->role ?? '';

        if (in_array($role, [
            'super_admin',
            'pimpinan',
            'operator'
        ], true)) {
            return true;
        }

        if ($role === 'ketua_tim') {
            return (int) $penugasan->ketua_id === (int) $user->id;
        }

        if ($role === 'pegawai') {
            return (int) $penugasan->ditugaskan_kepada === (int) $user->id;
        }

        return false;
    }
}
