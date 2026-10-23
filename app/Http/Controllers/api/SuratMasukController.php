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
    public function index(Request $request): JsonResponse
    {
        $query = SuratMasuk::with('dicatatOleh');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('nomor_surat', 'like', '%' . $request->search . '%')
                    ->orWhere('perihal', 'like', '%' . $request->search . '%')
                    ->orWhere('asal_surat', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Daftar surat masuk berhasil diambil.',
            'data' => SuratMasukResource::collection(
                $query->latest()->get()
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

        $suratMasuk->load('dicatatOleh');

        /*
         * Notifikasi WhatsApp hanya dijalankan saat CREATE.
         * Tidak dijalankan pada index, show, update, updateStatus,
         * maupun destroy.
         */
        $this->kirimNotifikasiSuratBaru($suratMasuk);

        return response()->json([
            'status' => 'success',
            'message' => 'Surat masuk berhasil ditambahkan.',
            'data' => new SuratMasukResource($suratMasuk),
        ], 201);
    }

    /**
     * Mengirim satu permintaan API Fonnte untuk semua ketua tim aktif.
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

            // Ambil nomor HP ketua tim aktif dan hilangkan duplikat.
            $nomorHp = DB::table('users')
                ->where('role', 'ketua_tim')
                ->where('status', 'aktif')
                ->whereNotNull('no_hp')
                ->where('no_hp', '<>', '')
                ->pluck('no_hp')
                ->map(function ($nomor) {
                    return trim($nomor);
                })
                ->filter()
                ->unique()
                ->values();

            if ($nomorHp->isEmpty()) {
                Log::info(
                    'Notifikasi surat masuk dilewati: tidak ada nomor ketua tim aktif.',
                    [
                        'surat_masuk_id' => $suratMasuk->id,
                    ]
                );

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
                'Silakan periksa surat masuk melalui aplikasi BPSKp.',
            ]);

            // Satu request Fonnte dengan daftar nomor yang unik.
            $response = Http::withHeaders([
                'Authorization' => $token,
            ])
                ->asForm()
                ->timeout(15)
                ->post($url, [
                    'target' => $nomorHp->implode(','),
                    'message' => $pesan,
                ]);

            if (!$response->successful()) {
                Log::warning(
                    'Fonnte gagal mengirim notifikasi surat masuk.',
                    [
                        'surat_masuk_id' => $suratMasuk->id,
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
                        'response' => $hasil,
                    ]
                );

                return;
            }

            Log::info(
                'Permintaan notifikasi surat masuk telah dikirim ke Fonnte.',
                [
                    'surat_masuk_id' => $suratMasuk->id,
                    'jumlah_nomor_tujuan' => $nomorHp->count(),
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
        $suratMasuk = SuratMasuk::with('dicatatOleh')->find($id);

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
                Rule::unique('tb_surat_masuk', 'nomor_surat')->ignore($id),
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

        $suratMasuk->load('dicatatOleh');

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