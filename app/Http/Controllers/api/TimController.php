<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TimController extends Controller
{
    /**
     * Daftar tim beserta nama ketua.
     * Khusus pimpinan: sertakan daftar anggota setiap tim.
     */
    public function index(Request $request)
    {
        $query = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select(
                'tb_tim.*',
                'users.name as nama_ketua'
            )
            ->orderBy('tb_tim.created_at', 'desc');

        if ($request->filled('search')) {
            $query->where(
                'tb_tim.nama_tim',
                'like',
                '%' . $request->search . '%'
            );
        }

        if ($request->filled('status')) {
            $query->where('tb_tim.status', $request->status);
        }

        $daftarTim = $query->get();

        if ($request->user() && $request->user()->role === 'pimpinan') {
            foreach ($daftarTim as $tim) {
                $tim->anggota = DB::table('tb_anggota_tim')
                    ->join(
                        'users',
                        'tb_anggota_tim.pegawai_id',
                        '=',
                        'users.id'
                    )
                    ->select(
                        'tb_anggota_tim.id as id_keanggotaan',
                        'users.id as pegawai_id',
                        'users.name',
                        'users.role'
                    )
                    ->where('tb_anggota_tim.tim_id', $tim->id)
                    ->orderBy('users.name')
                    ->get();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Data tim berhasil diambil.',
            'data' => $daftarTim
        ]);
    }

    /**
     * Detail tim beserta daftar anggotanya.
     */
    public function show($id)
    {
        $tim = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select(
                'tb_tim.*',
                'users.name as nama_ketua'
            )
            ->where('tb_tim.id', $id)
            ->first();

        if (!$tim) {
            return response()->json([
                'success' => false,
                'message' => 'Data tim tidak ditemukan.'
            ], 404);
        }

        $tim->anggota = DB::table('tb_anggota_tim')
            ->join(
                'users',
                'tb_anggota_tim.pegawai_id',
                '=',
                'users.id'
            )
            ->select(
                'tb_anggota_tim.id as id_keanggotaan',
                'users.id as pegawai_id',
                'users.name',
                'users.role'
            )
            ->where('tb_anggota_tim.tim_id', $id)
            ->orderBy('users.name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tim
        ]);
    }

    /**
     * Membuat tim baru.
     *
     * Hanya pegawai yang boleh menjadi ketua.
     * Jika pegawai masih menjadi anggota tim,
     * keanggotaannya otomatis dihapus.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_tim' => [
                'required',
                'string',
                'max:255'
            ],

            'ketua_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', 'pegawai')
            ],

            'keterangan' => [
                'nullable',
                'string'
            ],

            'status' => [
                'nullable',
                Rule::in(['aktif', 'nonaktif'])
            ],
        ], [
            'ketua_id.exists' => 'Ketua tim hanya boleh dipilih dari pengguna dengan role pegawai.'
        ]);

        $id = DB::transaction(function () use ($validated) {

            $waktu = now();

            // Kunci data calon ketua agar tidak diproses
            // bersamaan oleh transaksi tim lainnya.
            $calonKetua = DB::table('users')
                ->where('id', $validated['ketua_id'])
                ->lockForUpdate()
                ->first();

            if (!$calonKetua || $calonKetua->role !== 'pegawai') {
                throw ValidationException::withMessages([
                    'ketua_id' => [
                        'Ketua tim hanya boleh berasal dari pegawai.'
                    ]
                ]);
            }

            // Pastikan pegawai belum menjadi ketua tim lain.
            $sudahMenjadiKetua = DB::table('tb_tim')
                ->where('ketua_id', $calonKetua->id)
                ->exists();

            if ($sudahMenjadiKetua) {
                throw ValidationException::withMessages([
                    'ketua_id' => [
                        'Pegawai tersebut sudah menjadi ketua tim lain.'
                    ]
                ]);
            }

            // Jika masih menjadi anggota tim,
            // keluarkan dari seluruh tim terlebih dahulu.
            DB::table('tb_anggota_tim')
                ->where('pegawai_id', $calonKetua->id)
                ->delete();

            // Buat tim dengan menyimpan role sebelumnya.
            $id = DB::table('tb_tim')->insertGetId([
                'nama_tim' => $validated['nama_tim'],
                'ketua_id' => $calonKetua->id,
                'role_ketua_sebelumnya' => $calonKetua->role,
                'keterangan' => $validated['keterangan'] ?? null,
                'status' => $validated['status'] ?? 'aktif',
                'created_at' => $waktu,
                'updated_at' => $waktu
            ]);

            // Ubah role pegawai menjadi ketua_tim.
            DB::table('users')
                ->where('id', $calonKetua->id)
                ->update([
                    'role' => 'ketua_tim',
                    'updated_at' => $waktu
                ]);

            return $id;
        });

        $tim = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select(
                'tb_tim.*',
                'users.name as nama_ketua'
            )
            ->where('tb_tim.id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Tim berhasil dibuat.',
            'data' => $tim
        ], 201);
    }

    /**
     * Mengubah data tim.
     *
     * Jika ketua tidak berubah:
     * - Perubahan nama, keterangan, dan status tetap diperbolehkan.
     *
     * Jika ketua diganti:
     * 1. Ketua baru harus ber-role pegawai.
     * 2. Ketua lama dikembalikan ke role sebelumnya.
     * 3. Keanggotaan ketua baru di tim lain dihapus.
     * 4. Role ketua baru diubah menjadi ketua_tim.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'nama_tim' => [
                'sometimes',
                'required',
                'string',
                'max:255'
            ],

            // Validasi hanya memastikan ID pengguna tersedia.
            // Pemeriksaan role pegawai dilakukan di dalam transaksi
            // apabila ketua benar-benar diganti.
            'ketua_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id'
            ],

            'keterangan' => [
                'sometimes',
                'nullable',
                'string'
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(['aktif', 'nonaktif'])
            ],
        ], [
            'ketua_id.exists' => 'Pengguna yang dipilih sebagai ketua tim tidak ditemukan.'
        ]);

        if (empty($validated)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada data yang diperbarui.'
            ], 422);
        }

        DB::transaction(function () use ($validated, $id) {

            $waktu = now();

            $tim = DB::table('tb_tim')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$tim) {
                throw ValidationException::withMessages([
                    'tim' => ['Data tim tidak ditemukan.']
                ]);
            }

            // Proses pergantian ketua hanya jika ID berubah.
            if (
                isset($validated['ketua_id']) &&
                (int) $validated['ketua_id'] !== (int) $tim->ketua_id
            ) {
                $calonKetua = DB::table('users')
                    ->where('id', $validated['ketua_id'])
                    ->lockForUpdate()
                    ->first();

                // Ketua baru wajib berasal dari pegawai.
                if (!$calonKetua || $calonKetua->role !== 'pegawai') {
                    throw ValidationException::withMessages([
                        'ketua_id' => [
                            'Ketua tim hanya boleh berasal dari pegawai.'
                        ]
                    ]);
                }

                // Tidak boleh menjadi ketua di tim lain.
                $sudahMenjadiKetua = DB::table('tb_tim')
                    ->where('ketua_id', $calonKetua->id)
                    ->where('id', '!=', $id)
                    ->exists();

                if ($sudahMenjadiKetua) {
                    throw ValidationException::withMessages([
                        'ketua_id' => [
                            'Pegawai tersebut sudah menjadi ketua tim lain.'
                        ]
                    ]);
                }

                // Pulihkan role ketua lama.
                $ketuaLama = DB::table('users')
                    ->where('id', $tim->ketua_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $ketuaLama &&
                    $ketuaLama->role === 'ketua_tim' &&
                    !empty($tim->role_ketua_sebelumnya)
                ) {
                    DB::table('users')
                        ->where('id', $ketuaLama->id)
                        ->update([
                            'role' => $tim->role_ketua_sebelumnya,
                            'updated_at' => $waktu
                        ]);
                }

                // Keluarkan calon ketua dari seluruh tim
                // jika sebelumnya menjadi anggota.
                DB::table('tb_anggota_tim')
                    ->where('pegawai_id', $calonKetua->id)
                    ->delete();

                // Ubah role calon ketua menjadi ketua_tim.
                DB::table('users')
                    ->where('id', $calonKetua->id)
                    ->update([
                        'role' => 'ketua_tim',
                        'updated_at' => $waktu
                    ]);

                // Simpan role sebelumnya milik ketua baru.
                $validated['role_ketua_sebelumnya'] = $calonKetua->role;
            }

            $validated['updated_at'] = $waktu;

            DB::table('tb_tim')
                ->where('id', $id)
                ->update($validated);
        });

        $timTerbaru = DB::table('tb_tim')
            ->join('users', 'tb_tim.ketua_id', '=', 'users.id')
            ->select(
                'tb_tim.*',
                'users.name as nama_ketua'
            )
            ->where('tb_tim.id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Data tim berhasil diperbarui.',
            'data' => $timTerbaru
        ]);
    }

    /**
     * Menghapus tim.
     *
     * Role ketua dikembalikan ke role sebelumnya.
     * Anggota ikut terhapus melalui cascadeOnDelete.
     */
    public function destroy($id)
    {
        DB::transaction(function () use ($id) {

            $tim = DB::table('tb_tim')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$tim) {
                throw ValidationException::withMessages([
                    'tim' => ['Data tim tidak ditemukan.']
                ]);
            }

            $ketua = DB::table('users')
                ->where('id', $tim->ketua_id)
                ->lockForUpdate()
                ->first();

            // Pulihkan role ketua sebelum tim dihapus.
            if (
                $ketua &&
                $ketua->role === 'ketua_tim' &&
                !empty($tim->role_ketua_sebelumnya)
            ) {
                DB::table('users')
                    ->where('id', $ketua->id)
                    ->update([
                        'role' => $tim->role_ketua_sebelumnya,
                        'updated_at' => now()
                    ]);
            }

            DB::table('tb_tim')
                ->where('id', $id)
                ->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Tim berhasil dihapus.'
        ]);
    }

    /**
     * Menambahkan anggota ke dalam tim.
     *
     * Hanya pegawai yang boleh menjadi anggota.
     * Pegawai tidak boleh menjadi anggota lebih dari satu tim
     * atau menjadi ketua tim.
     */
    public function tambahAnggota(Request $request, $id)
    {
        $validated = $request->validate([
            'pegawai_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', 'pegawai')
            ],
        ], [
            'pegawai_id.exists' => 'Anggota tim hanya boleh dipilih dari pengguna dengan role pegawai.'
        ]);

        DB::transaction(function () use ($validated, $id) {

            $pegawai = DB::table('users')
                ->where('id', $validated['pegawai_id'])
                ->lockForUpdate()
                ->first();

            if (!$pegawai || $pegawai->role !== 'pegawai') {
                throw ValidationException::withMessages([
                    'pegawai_id' => [
                        'Anggota tim hanya boleh berasal dari pegawai.'
                    ]
                ]);
            }

            $tim = DB::table('tb_tim')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$tim) {
                throw ValidationException::withMessages([
                    'tim' => ['Tim tidak ditemukan.']
                ]);
            }

            // Ketua tidak boleh menjadi anggota.
            $sebagaiKetua = DB::table('tb_tim')
                ->where('ketua_id', $pegawai->id)
                ->exists();

            if ($sebagaiKetua) {
                throw ValidationException::withMessages([
                    'pegawai_id' => [
                        'Pegawai tersebut sudah menjadi ketua tim.'
                    ]
                ]);
            }

            // Satu pegawai hanya boleh menjadi anggota satu tim.
            $sebagaiAnggota = DB::table('tb_anggota_tim')
                ->where('pegawai_id', $pegawai->id)
                ->exists();

            if ($sebagaiAnggota) {
                throw ValidationException::withMessages([
                    'pegawai_id' => [
                        'Pegawai tersebut sudah tergabung dalam tim lain.'
                    ]
                ]);
            }

            DB::table('tb_anggota_tim')->insert([
                'tim_id' => $id,
                'pegawai_id' => $pegawai->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Anggota berhasil ditambahkan ke tim.'
        ], 201);
    }

    /**
     * Menghapus anggota dari tim.
     */
    public function hapusAnggota($id, $pegawai_id)
    {
        $timExists = DB::table('tb_tim')
            ->where('id', $id)
            ->exists();

        if (!$timExists) {
            return response()->json([
                'success' => false,
                'message' => 'Tim tidak ditemukan.'
            ], 404);
        }

        $deleted = DB::table('tb_anggota_tim')
            ->where('tim_id', $id)
            ->where('pegawai_id', $pegawai_id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak ditemukan di tim ini.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Anggota berhasil dihapus dari tim.'
        ]);
    }

    /**
     * Mengambil pegawai yang tersedia untuk menjadi anggota.
     *
     * Pegawai yang sudah menjadi ketua atau anggota
     * tidak akan ditampilkan.
     */
    public function pegawaiTersedia()
    {
        $pegawai = DB::table('users')
            ->select(
                'users.id',
                'users.name',
                'users.role'
            )
            ->where('users.role', 'pegawai')
            ->whereNotIn('users.id', function ($query) {
                $query->select('pegawai_id')
                    ->from('tb_anggota_tim');
            })
            ->whereNotIn('users.id', function ($query) {
                $query->select('ketua_id')
                    ->from('tb_tim');
            })
            ->orderBy('users.name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar pegawai yang tersedia berhasil diambil.',
            'data' => $pegawai
        ]);
    }

    /**
     * Mengirim pesan melalui Fonnte.
     * Dipertahankan tanpa perubahan alur.
     */
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'target' => [
                'required',
                'string',
                'regex:/^[0-9]{8,16}$/'
            ],
            'message' => [
                'required',
                'string',
                'max:2000'
            ],
        ], [
            'target.required' => 'Nomor tujuan wajib diisi.',
            'target.regex' => 'Format nomor tujuan tidak valid. Gunakan angka dengan kode negara, misalnya 6281234567890.',
            'message.required' => 'Pesan wajib diisi.',
            'message.max' => 'Pesan maksimal 2000 karakter.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = config('services.fonnte.token');
        $url = config('services.fonnte.url');

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => 'Konfigurasi API Fonnte belum tersedia.',
            ], 500);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $token,
            ])
                ->asForm()
                ->timeout(30)
                ->post($url, [
                    'target' => $validator->validated()['target'],
                    'message' => $validator->validated()['message'],
                ]);

            if (!$response->successful()) {
                Log::warning('Fonnte API HTTP error', [
                    'status' => $response->status(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Layanan pengiriman pesan sedang bermasalah.',
                ], 502);
            }

            $result = $response->json();

            if (!is_array($result)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Respons dari layanan Fonnte tidak valid.',
                ], 502);
            }

            $accepted = ($result['status'] ?? false) === true
                || ($result['status'] ?? null) === 1
                || ($result['status'] ?? null) === 'true';

            return response()->json([
                'success' => $accepted,
                'message' => $accepted
                    ? 'Permintaan pengiriman pesan berhasil diproses.'
                    : ($result['reason'] ?? 'Fonnte menolak permintaan pengiriman pesan.'),
                'data' => $result,
            ], $accepted ? 200 : 502);

        } catch (Throwable $e) {
            Log::error('Fonnte API connection error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat terhubung ke layanan Fonnte.',
            ], 502);
        }
    }
}