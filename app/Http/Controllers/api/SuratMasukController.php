<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SuratMasukResource;
use App\Models\SuratMasuk;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class SuratMasukController extends Controller
{
    /**
     * Query surat masuk dengan informasi tim dan ketua tim.
     *
     * Menggunakan Query Builder, tanpa Model Tim.
     */
    private function querySuratMasuk()
    {
        return SuratMasuk::query()
            ->leftJoin(
                'tb_tim',
                'tb_surat_masuk.tim_id',
                '=',
                'tb_tim.id'
            )
            ->leftJoin(
                'users as ketua_tim',
                'tb_tim.ketua_id',
                '=',
                'ketua_tim.id'
            )
            ->select(
                'tb_surat_masuk.*',
                'tb_tim.nama_tim',
                'tb_tim.ketua_id',
                'ketua_tim.name as nama_ketua',
                'ketua_tim.no_hp as hp_ketua'
            )
            ->with('dicatatOleh');
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->querySuratMasuk();

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where(
                    'tb_surat_masuk.nomor_surat',
                    'like',
                    '%' . $search . '%'
                )
                    ->orWhere(
                        'tb_surat_masuk.perihal',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'tb_surat_masuk.asal_surat',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'tb_tim.nama_tim',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'ketua_tim.name',
                        'like',
                        '%' . $search . '%'
                    );
            });
        }

        if ($request->filled('status')) {
            $query->where(
                'tb_surat_masuk.status',
                $request->status
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Daftar surat masuk berhasil diambil.',
            'data' => SuratMasukResource::collection(
                $query->latest('tb_surat_masuk.created_at')->get()
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nomor_surat' => [
                'required',
                'string',
                Rule::unique('tb_surat_masuk', 'nomor_surat'),
            ],

            'tanggal_surat' => [
                'required',
                'date',
            ],

            'tanggal_diterima' => [
                'required',
                'date',
            ],

            'asal_surat' => [
                'required',
                'string',
            ],

            'perihal' => [
                'required',
                'string',
            ],

            'isi_ringkas' => [
                'nullable',
                'string',
            ],

            'tanggal_acara' => [
                'nullable',
                'date',
            ],

            'waktu_mulai' => [
                'nullable',
                'date_format:H:i',
            ],

            'waktu_selesai' => [
                'nullable',
                'date_format:H:i',
            ],

            'lokasi' => [
                'nullable',
                'string',
            ],

            // Tim tujuan surat masuk.
            'tim_id' => [
                'required',
                Rule::exists('tb_tim', 'id')
                    ->where('status', 'aktif'),
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'baru',
                    'diproses',
                    'selesai',
                ]),
            ],

            'file_surat' => [
                'nullable',
                'mimes:pdf,jpg,png',
                'max:2048',
            ],
        ]);

        $validated['dicatat_oleh'] = $request->user()->id;
        $validated['status'] = 'baru';

        if ($request->hasFile('file_surat')) {
            $validated['file_surat'] = $request
                ->file('file_surat')
                ->store('surat_masuk', 'public');
        }

        // Simpan surat terlebih dahulu.
        $suratMasuk = SuratMasuk::create($validated);

        // Ambil kembali data lengkap beserta tim dan ketua.
        $suratMasuk = $this->querySuratMasuk()
            ->where('tb_surat_masuk.id', $suratMasuk->id)
            ->first();

        /*
         * Notifikasi WhatsApp hanya dijalankan saat CREATE.
         * Tidak dijalankan pada index, show, update,
         * updateStatus, maupun destroy.
         */
        $this->kirimNotifikasiSuratBaru($suratMasuk);

        return response()->json([
            'status' => 'success',
            'message' => 'Surat masuk berhasil ditambahkan.',
            'data' => new SuratMasukResource($suratMasuk),
        ], 201);
    }

    /**
     * Mengirim notifikasi WhatsApp kepada ketua tim tujuan.
     *
     * Tidak menggunakan Model Tim.
     */
    private function kirimNotifikasiSuratBaru(
        SuratMasuk $suratMasuk
    ): void {
        try {
            $token = config('services.fonnte.token');

            $url = config(
                'services.fonnte.url',
                'https://api.fonnte.com/send'
            );

            if (empty($token) || empty($url)) {
                Log::warning(
                    'Notifikasi surat masuk tidak dikirim: konfigurasi Fonnte belum tersedia.',
                    [
                        'surat_masuk_id' => $suratMasuk->id,
                    ]
                );

                return;
            }

            if (!$suratMasuk->tim_id) {
                Log::info(
                    'Notifikasi surat masuk dilewati: tim tujuan belum ditentukan.',
                    [
                        'surat_masuk_id' => $suratMasuk->id,
                    ]
                );

                return;
            }

            /*
             * Ambil ketua tim berdasarkan tim_id surat masuk.
             * Hanya ketua tim aktif dengan nomor HP aktif.
             */
            $ketuaTim = DB::table('tb_tim')
                ->join(
                    'users',
                    'tb_tim.ketua_id',
                    '=',
                    'users.id'
                )
                ->where('tb_tim.id', $suratMasuk->tim_id)
                ->where('tb_tim.status', 'aktif')
                ->where('users.role', 'ketua_tim')
                ->where('users.status', 'aktif')
                ->whereNotNull('users.no_hp')
                ->where('users.no_hp', '<>', '')
                ->select([
                    'tb_tim.nama_tim',
                    'tb_tim.ketua_id',
                    'users.name as nama_ketua',
                    'users.no_hp',
                ])
                ->first();

            if (!$ketuaTim) {
                Log::info(
                    'Notifikasi surat masuk dilewati: ketua tim tidak memiliki nomor HP aktif.',
                    [
                        'surat_masuk_id' => $suratMasuk->id,
                        'tim_id' => $suratMasuk->tim_id,
                    ]
                );

                return;
            }

            $nomorHp = trim($ketuaTim->no_hp);

            if ($nomorHp === '') {
                return;
            }

            $pesan = implode("\n", [
                '📩 SURAT MASUK BARU',
                '',
                'Nomor surat: ' . $suratMasuk->nomor_surat,
                'Tanggal surat: ' . $suratMasuk->tanggal_surat,
                'Tanggal diterima: ' . $suratMasuk->tanggal_diterima,
                'Asal surat: ' . $suratMasuk->asal_surat,
                'Perihal: ' . $suratMasuk->perihal,
                '',
                'Tim tujuan: ' . $ketuaTim->nama_tim,
                'Ketua tim: ' . $ketuaTim->nama_ketua,
                '',
                'Anda merupakan ketua tim yang ditunjuk untuk menangani surat ini.',
                '',
                'Silakan periksa surat masuk melalui aplikasi BPSKp.',
            ]);

            // Satu request Fonnte untuk ketua tim tujuan.
            $response = Http::withHeaders([
                'Authorization' => $token,
            ])
                ->asForm()
                ->timeout(15)
                ->post($url, [
                    'target' => $nomorHp,
                    'message' => $pesan,
                ]);

            if (!$response->successful()) {
                Log::warning(
                    'Fonnte gagal mengirim notifikasi surat masuk.',
                    [
                        'surat_masuk_id' => $suratMasuk->id,
                        'tim_id' => $suratMasuk->tim_id,
                        'http_status' => $response->status(),
                        'response' => $response->body(),
                    ]
                );

                return;
            }

            $hasil = $response->json();

            if (
                is_array($hasil) &&
                array_key_exists('status', $hasil) &&
                $hasil['status'] === false
            ) {
                Log::warning(
                    'Fonnte menolak pengiriman notifikasi surat masuk.',
                    [
                        'surat_masuk_id' => $suratMasuk->id,
                        'tim_id' => $suratMasuk->tim_id,
                        'response' => $hasil,
                    ]
                );

                return;
            }

            Log::info(
                'Notifikasi surat masuk telah dikirim kepada ketua tim tujuan.',
                [
                    'surat_masuk_id' => $suratMasuk->id,
                    'tim_id' => $suratMasuk->tim_id,
                    'nama_tim' => $ketuaTim->nama_tim,
                    'nama_ketua' => $ketuaTim->nama_ketua,
                ]
            );

        } catch (Throwable $e) {
            // Kegagalan WhatsApp tidak membatalkan penyimpanan surat.
            Log::error(
                'Terjadi kesalahan saat mengirim notifikasi surat masuk.',
                [
                    'surat_masuk_id' => $suratMasuk->id,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }

    public function show(string $id): JsonResponse
    {
        $suratMasuk = $this->querySuratMasuk()
            ->where('tb_surat_masuk.id', $id)
            ->first();

        if (!$suratMasuk) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Detail surat masuk berhasil diambil.',
            'data' => new SuratMasukResource($suratMasuk),
        ], 200);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $suratMasuk = SuratMasuk::find($id);

        if (!$suratMasuk) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'nomor_surat' => [
                'sometimes',
                'string',
                Rule::unique('tb_surat_masuk', 'nomor_surat')
                    ->ignore($id),
            ],

            'tanggal_surat' => [
                'sometimes',
                'date',
            ],

            'tanggal_diterima' => [
                'sometimes',
                'date',
            ],

            'asal_surat' => [
                'sometimes',
                'string',
            ],

            'perihal' => [
                'sometimes',
                'string',
            ],

            'isi_ringkas' => [
                'nullable',
                'string',
            ],

            'tanggal_acara' => [
                'nullable',
                'date',
            ],

            'waktu_mulai' => [
                'nullable',
                'date_format:H:i',
            ],

            'waktu_selesai' => [
                'nullable',
                'date_format:H:i',
            ],

            'lokasi' => [
                'nullable',
                'string',
            ],

            // Tim tujuan dapat diubah saat edit.
            'tim_id' => [
                'sometimes',
                'nullable',
                Rule::exists('tb_tim', 'id')
                    ->where('status', 'aktif'),
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'baru',
                    'diproses',
                    'selesai',
                ]),
            ],

            'file_surat' => [
                'nullable',
                'mimes:pdf,jpg,png',
                'max:2048',
            ],
        ]);

        if ($request->hasFile('file_surat')) {
            if (
                $suratMasuk->file_surat &&
                Storage::disk('public')->exists($suratMasuk->file_surat)
            ) {
                Storage::disk('public')->delete(
                    $suratMasuk->file_surat
                );
            }

            $validated['file_surat'] = $request
                ->file('file_surat')
                ->store('surat_masuk', 'public');
        }

        $suratMasuk->update($validated);

        // Ambil ulang data beserta tim dan ketua tim.
        $suratMasuk = $this->querySuratMasuk()
            ->where('tb_surat_masuk.id', $id)
            ->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil diubah.',
            'data' => new SuratMasukResource($suratMasuk),
        ]);
    }

    public function updateStatus(
        Request $request,
        string $id
    ): JsonResponse {
        $suratMasuk = SuratMasuk::find($id);

        if (!$suratMasuk) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data tidak ditemukan.',
            ], 404);
        }

        $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'baru',
                    'diproses',
                    'selesai',
                ]),
            ],
        ]);

        $suratMasuk->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Status surat berhasil diubah.',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $suratMasuk = SuratMasuk::find($id);

        if (!$suratMasuk) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data tidak ditemukan.',
            ], 404);
        }

        if (
            $suratMasuk->file_surat &&
            Storage::disk('public')->exists($suratMasuk->file_surat)
        ) {
            Storage::disk('public')->delete(
                $suratMasuk->file_surat
            );
        }

        $suratMasuk->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Surat berhasil dihapus.',
        ]);
    }
}
