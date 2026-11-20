<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Throwable;

class KegiatanController extends Controller
{
    /**
     * Pilihan status dinas kegiatan.
     * dalam_dinas = Dalam Dinas, luar_dinas = Luar Dinas, non_dinas = Non Dinas.
     */
    private const STATUS_DINAS = [
        'dalam_dinas',
        'luar_dinas',
        'non_dinas'
    ];

    /**
     * ======================================================
     * DAFTAR KEGIATAN
     * ======================================================
     *
     * Role:
     * - operator  : melihat seluruh kegiatan.
     * - ketua_tim : hanya melihat kegiatan dari tim sendiri.
     *
     * Filter:
     * - search : nama, uraian, dan lokasi kegiatan.
     * - status : baru, proses, selesai.
     * - status_dinas : dalam_dinas, luar_dinas, non_dinas.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user || !in_array($user->role, ['operator', 'ketua_tim'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat kegiatan.'
                ], 403);
            }

            $query = DB::table('tb_kegiatan')
                ->select(
                    'id',
                    'tim_id',
                    'nama_kegiatan',
                    'uraian_kegiatan',
                    'lokasi',
                    'tanggal_mulai',
                    'tanggal_selesai',
                    'volume_jam',
                    'jam_mulai',
                    'jam_selesai',
                    'status',
                    'status_dinas',
                    'created_at',
                    'updated_at'
                );

            // Ketua tim hanya melihat kegiatan tim sendiri.
            if ($user->role === 'ketua_tim') {

                $timId = $this->getTimKetua($user->id);

                if (!$timId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum terdaftar sebagai ketua tim.'
                    ], 403);
                }

                $query->where('tim_id', $timId);
            }

            // Pencarian kegiatan.
            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('nama_kegiatan', 'like', "%{$search}%")
                        ->orWhere('uraian_kegiatan', 'like', "%{$search}%")
                        ->orWhere('lokasi', 'like', "%{$search}%");
                });
            }

            // Filter status.
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Filter status dinas.
            if ($request->filled('status_dinas')) {
                $query->where('status_dinas', $request->status_dinas);
            }

            $data = $query
                ->orderBy('tanggal_mulai', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Daftar kegiatan berhasil diambil.',
                'data' => $data
            ], 200);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data kegiatan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * DETAIL KEGIATAN
     * ======================================================
     *
     * Operator:
     * - Dapat melihat semua kegiatan.
     *
     * Ketua tim:
     * - Hanya dapat melihat kegiatan tim sendiri.
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            if (!$user || !in_array($user->role, ['operator', 'ketua_tim'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat detail kegiatan.'
                ], 403);
            }

            $query = DB::table('tb_kegiatan')
                ->where('id', $id);

            if ($user->role === 'ketua_tim') {

                $timId = $this->getTimKetua($user->id);

                if (!$timId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum terdaftar sebagai ketua tim.'
                    ], 403);
                }

                $query->where('tim_id', $timId);
            }

            $kegiatan = $query->first();

            if (!$kegiatan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data kegiatan tidak ditemukan.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail kegiatan berhasil diambil.',
                'data' => $kegiatan
            ], 200);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil detail kegiatan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * TAMBAH KEGIATAN
     * ======================================================
     *
     * Operator:
     * - Wajib memilih tim melalui tim_id.
     *
     * Ketua tim:
     * - tim_id ditentukan otomatis dari tim yang dipimpin.
     * - tim_id dari request tidak digunakan.
     *
     * Status awal otomatis:
     * baru.
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user || !in_array($user->role, ['operator', 'ketua_tim'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menambahkan kegiatan.'
                ], 403);
            }

            // Ketua tim otomatis menggunakan tim miliknya.
            if ($user->role === 'ketua_tim') {

                $timId = $this->getTimKetua($user->id);

                if (!$timId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum terdaftar sebagai ketua tim.'
                    ], 403);
                }

                // Abaikan tim_id yang dikirim dari aplikasi.
                $request->merge([
                    'tim_id' => $timId
                ]);
            }

            // Operator tetap wajib mengirim tim_id.
            $validator = $this->validateKegiatan($request);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data kegiatan tidak valid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $now = now();

            $id = DB::table('tb_kegiatan')->insertGetId([
                'tim_id' => $validated['tim_id'],
                'nama_kegiatan' => $validated['nama_kegiatan'],
                'uraian_kegiatan' => $validated['uraian_kegiatan'] ?? null,
                'lokasi' => $validated['lokasi'],
                'tanggal_mulai' => $validated['tanggal_mulai'],
                'tanggal_selesai' => $validated['tanggal_selesai'],
                'volume_jam' => $validated['volume_jam'],
                'jam_mulai' => $validated['jam_mulai'] ?? null,
                'jam_selesai' => $validated['jam_selesai'] ?? null,
                'status_dinas' => $validated['status_dinas'],

                'status' => 'baru',

                'created_at' => $now,
                'updated_at' => $now
            ]);

            $kegiatan = DB::table('tb_kegiatan')
                ->where('id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Kegiatan berhasil ditambahkan.',
                'data' => $kegiatan
            ], 201);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan kegiatan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * UPDATE KEGIATAN
     * ======================================================
     *
     * Operator:
     * - Dapat mengubah semua kegiatan.
     * - Dapat mengganti tim melalui tim_id.
     *
     * Ketua tim:
     * - Hanya dapat mengubah kegiatan tim sendiri.
     * - tim_id tidak dapat diganti.
     *
     * Status tidak dapat diubah melalui endpoint ini.
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();

            if (!$user || !in_array($user->role, ['operator', 'ketua_tim'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengubah kegiatan.'
                ], 403);
            }

            $query = DB::table('tb_kegiatan')
                ->where('id', $id);

            $timIdKetua = null;

            if ($user->role === 'ketua_tim') {

                $timIdKetua = $this->getTimKetua($user->id);

                if (!$timIdKetua) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum terdaftar sebagai ketua tim.'
                    ], 403);
                }

                $query->where('tim_id', $timIdKetua);
            }

            $kegiatan = $query->first();

            if (!$kegiatan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data kegiatan tidak ditemukan.'
                ], 404);
            }

            // Ketua tim selalu mempertahankan tim kegiatan sebelumnya.
            if ($user->role === 'ketua_tim') {
                $request->merge([
                    'tim_id' => $kegiatan->tim_id
                ]);
            }

            $validator = $this->validateKegiatan($request, true);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data kegiatan tidak valid.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $updateData = [
                'nama_kegiatan' => $validated['nama_kegiatan'],
                'uraian_kegiatan' => $validated['uraian_kegiatan'] ?? null,
                'lokasi' => $validated['lokasi'],
                'tanggal_mulai' => $validated['tanggal_mulai'],
                'tanggal_selesai' => $validated['tanggal_selesai'],
                'volume_jam' => $validated['volume_jam'],
                'jam_mulai' => $validated['jam_mulai'] ?? null,
                'jam_selesai' => $validated['jam_selesai'] ?? null,
                'updated_at' => now()
            ];

            // Status dinas hanya diubah jika dikirim.
            if (array_key_exists('status_dinas', $validated)) {
                $updateData['status_dinas'] = $validated['status_dinas'];
            }

            // Tim hanya dapat diganti oleh operator.
            if ($user->role === 'operator' && array_key_exists('tim_id', $validated)) {
                $updateData['tim_id'] = $validated['tim_id'];
            }

            DB::table('tb_kegiatan')
                ->where('id', $id)
                ->update($updateData);

            $data = DB::table('tb_kegiatan')
                ->where('id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Data kegiatan berhasil diperbarui.',
                'data' => $data
            ], 200);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui kegiatan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * HAPUS KEGIATAN
     * ======================================================
     *
     * Operator:
     * - Dapat menghapus semua kegiatan.
     *
     * Ketua tim:
     * - Hanya dapat menghapus kegiatan tim sendiri.
     *
     * Kegiatan tidak dapat dihapus jika sudah memiliki
     * data penugasan.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();

            if (!$user || !in_array($user->role, ['operator', 'ketua_tim'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus kegiatan.'
                ], 403);
            }

            $query = DB::table('tb_kegiatan')
                ->where('id', $id);

            if ($user->role === 'ketua_tim') {

                $timId = $this->getTimKetua($user->id);

                if (!$timId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda belum terdaftar sebagai ketua tim.'
                    ], 403);
                }

                $query->where('tim_id', $timId);
            }

            $kegiatan = $query->first();

            if (!$kegiatan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data kegiatan tidak ditemukan.'
                ], 404);
            }

            // Periksa apakah kegiatan sudah memiliki penugasan.
            $memilikiPenugasan = DB::table('tb_penugasan')
                ->where('kegiatan_id', $id)
                ->exists();

            if ($memilikiPenugasan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kegiatan tidak dapat dihapus karena sudah memiliki data penugasan.'
                ], 409);
            }

            DB::table('tb_kegiatan')
                ->where('id', $id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Kegiatan berhasil dihapus.'
            ], 200);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus kegiatan.'
            ], 500);
        }
    }


    /**
     * ======================================================
     * AMBIL ID TIM KETUA
     * ======================================================
     *
     * Relasi:
     * tb_tim.ketua_id = users.id
     */
    private function getTimKetua($userId)
    {
        return DB::table('tb_tim')
            ->where('ketua_id', $userId)
            ->value('id');
    }


    /**
     * ======================================================
     * VALIDASI DATA KEGIATAN
     * ======================================================
     *
     * Ketentuan:
     * - Saat tambah, tim_id wajib diisi.
     * - Saat update, tim_id opsional.
     * - tim_id harus terdaftar di tb_tim.
     * - lokasi wajib diisi.
     *
     * - Volume >= 8 jam:
     *   jam_mulai dan jam_selesai tidak wajib.
     *
     * - Volume < 8 jam:
     *   jam_mulai dan jam_selesai wajib diisi.
     */
    private function validateKegiatan(
        Request $request,
        bool $isUpdate = false
    ): ValidatorInstance {

        $validator = Validator::make(
            $request->all(),
            [
                'tim_id' => $isUpdate
                    ? [
                        'sometimes',
                        'required',
                        'integer',
                        'exists:tb_tim,id'
                    ]
                    : [
                        'required',
                        'integer',
                        'exists:tb_tim,id'
                    ],

                'nama_kegiatan' => [
                    'required',
                    'string',
                    'max:255'
                ],

                'uraian_kegiatan' => [
                    'nullable',
                    'string'
                ],

                'lokasi' => [
                    'required',
                    'string'
                ],

                'tanggal_mulai' => [
                    'required',
                    'date'
                ],

                'tanggal_selesai' => [
                    'required',
                    'date',
                    'after_or_equal:tanggal_mulai'
                ],

                'status_dinas' => $isUpdate
                    ? [
                        'sometimes',
                        'required',
                        Rule::in(self::STATUS_DINAS)
                    ]
                    : [
                        'required',
                        Rule::in(self::STATUS_DINAS)
                    ],

                'volume_jam' => [
                    'required',
                    'numeric',
                    'min:0.01',
                    'max:999.99'
                ],

                'jam_mulai' => [
                    'nullable',
                    'date_format:H:i'
                ],

                'jam_selesai' => [
                    'nullable',
                    'date_format:H:i',
                    'after:jam_mulai'
                ]
            ],
            [
                'tim_id.required' => 'Tim wajib dipilih.',
                'tim_id.integer' => 'Tim tidak valid.',
                'tim_id.exists' => 'Tim yang dipilih tidak ditemukan.',

                'nama_kegiatan.required' => 'Nama kegiatan wajib diisi.',
                'nama_kegiatan.string' => 'Nama kegiatan harus berupa teks.',
                'nama_kegiatan.max' => 'Nama kegiatan maksimal 255 karakter.',

                'uraian_kegiatan.string' => 'Uraian kegiatan harus berupa teks.',

                'lokasi.required' => 'Lokasi kegiatan wajib diisi.',
                'lokasi.string' => 'Lokasi kegiatan harus berupa teks.',

                'tanggal_mulai.required' => 'Tanggal mulai wajib diisi.',
                'tanggal_mulai.date' => 'Tanggal mulai tidak valid.',

                'tanggal_selesai.required' => 'Tanggal selesai wajib diisi.',
                'tanggal_selesai.date' => 'Tanggal selesai tidak valid.',
                'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',

                'status_dinas.required' => 'Status dinas wajib dipilih.',
                'status_dinas.in' => 'Status dinas harus dalam_dinas, luar_dinas, atau non_dinas.',

                'volume_jam.required' => 'Volume jam wajib diisi.',
                'volume_jam.numeric' => 'Volume jam harus berupa angka.',
                'volume_jam.min' => 'Volume jam minimal 0,01 jam.',
                'volume_jam.max' => 'Volume jam maksimal 999,99 jam.',

                'jam_mulai.date_format' => 'Format jam mulai harus HH:MM.',
                'jam_selesai.date_format' => 'Format jam selesai harus HH:MM.',
                'jam_selesai.after' => 'Jam selesai harus setelah jam mulai.'
            ]
        );

        // Validasi tambahan berdasarkan volume jam.
        $validator->after(function (ValidatorInstance $validator) {

            $data = $validator->getData();

            if (
                !isset($data['volume_jam']) ||
                !is_numeric($data['volume_jam'])
            ) {
                return;
            }

            $volumeJam = (float) $data['volume_jam'];

            // Jika volume kurang dari 8 jam,
            // jam mulai dan jam selesai wajib diisi.
            if ($volumeJam < 8) {

                if (empty($data['jam_mulai'])) {
                    $validator->errors()->add(
                        'jam_mulai',
                        'Jam mulai wajib diisi jika volume kegiatan kurang dari 8 jam.'
                    );
                }

                if (empty($data['jam_selesai'])) {
                    $validator->errors()->add(
                        'jam_selesai',
                        'Jam selesai wajib diisi jika volume kegiatan kurang dari 8 jam.'
                    );
                }
            }
        });

        return $validator;
    }
}
