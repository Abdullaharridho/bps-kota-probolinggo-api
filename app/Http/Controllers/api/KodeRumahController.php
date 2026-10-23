<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class KodeRumahController extends Controller
{
    private string $tabelRumah = 'tb_kode_rumah';
    private string $tabelLog = 'tb_kode_rumah_log';

    /**
     * GET /api/kode-rumah
     * Daftar, pencarian, dan filter data kode rumah.
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'nullable|string|max:100',
            'idsubsls' => 'nullable|string|max:30',
            'nama_kecamatan' => 'nullable|string|max:100',
            'nama_kelurahan' => 'nullable|string|max:100',
            'nama_rt_rw' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ], [
            'q.max' => 'Pencarian maksimal 100 karakter.',
            'idsubsls.max' => 'Kode Sub-SLS maksimal 30 karakter.',
            'per_page.integer' => 'Jumlah data per halaman harus berupa angka.',
            'per_page.min' => 'Jumlah data minimal 1.',
            'per_page.max' => 'Maksimal 100 data per halaman.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter pencarian tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = DB::table($this->tabelRumah);

        if ($request->filled('q')) {
            $keyword = trim($request->input('q'));

            $query->where(function ($q) use ($keyword) {
                $q->where('idsubsls', 'like', "%{$keyword}%")
                    ->orWhere('no_bangunan', 'like', "%{$keyword}%")
                    ->orWhere('nama_kk', 'like', "%{$keyword}%")
                    ->orWhere('nama_usaha', 'like', "%{$keyword}%")
                    ->orWhere('nama_kecamatan', 'like', "%{$keyword}%")
                    ->orWhere('nama_kelurahan', 'like', "%{$keyword}%")
                    ->orWhere('nama_rt_rw', 'like', "%{$keyword}%");
            });
        }

        if ($request->filled('idsubsls')) {
            $query->where('idsubsls', $request->input('idsubsls'));
        }

        if ($request->filled('nama_kecamatan')) {
            $query->where(
                'nama_kecamatan',
                $request->input('nama_kecamatan')
            );
        }

        if ($request->filled('nama_kelurahan')) {
            $query->where(
                'nama_kelurahan',
                $request->input('nama_kelurahan')
            );
        }

        if ($request->filled('nama_rt_rw')) {
            $query->where(
                'nama_rt_rw',
                $request->input('nama_rt_rw')
            );
        }

        $perPage = (int) $request->input('per_page', 20);

        $hasil = $query
            ->orderBy('nama_kecamatan')
            ->orderBy('nama_kelurahan')
            ->orderBy('nama_rt_rw')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Data kode rumah berhasil diambil.',
            'data' => $hasil->items(),
            'pagination' => [
                'current_page' => $hasil->currentPage(),
                'per_page' => $hasil->perPage(),
                'total' => $hasil->total(),
                'last_page' => $hasil->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/kode-rumah/{id}
     * Detail data rumah.
     */
    public function show($id)
    {
        $rumah = DB::table($this->tabelRumah)
            ->where('id', $id)
            ->first();

        if (!$rumah) {
            return response()->json([
                'success' => false,
                'message' => 'Data kode rumah tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail kode rumah berhasil diambil.',
            'data' => $rumah,
        ]);
    }

    /**
     * POST /api/kode-rumah
     * Tambah data rumah sekaligus membuat log.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_kecamatan' => 'required|string|max:100',
            'nama_kelurahan' => 'required|string|max:100',
            'nama_rt_rw' => 'required|string|max:100',
            'idsubsls' => 'required|string|max:30',
            'no_bangunan' => 'required|string|max:20',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'nama_kk' => 'nullable|string|max:255',
            'nama_usaha' => 'nullable|string|max:255',
        ], [
            'nama_kecamatan.required' => 'Nama kecamatan wajib diisi.',
            'nama_kelurahan.required' => 'Nama kelurahan wajib diisi.',
            'nama_rt_rw.required' => 'Nama RT/RW wajib diisi.',
            'idsubsls.required' => 'Kode Sub-SLS wajib diisi.',
            'no_bangunan.required' => 'Nomor bangunan wajib diisi.',
            'latitude.numeric' => 'Latitude harus berupa angka.',
            'latitude.between' => 'Latitude harus berada antara -90 dan 90.',
            'longitude.numeric' => 'Longitude harus berupa angka.',
            'longitude.between' => 'Longitude harus berada antara -180 dan 180.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data kode rumah tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user()->id;
        $now = now();

        $data = [
            'nama_kecamatan' => $request->input('nama_kecamatan'),
            'nama_kelurahan' => $request->input('nama_kelurahan'),
            'nama_rt_rw' => $request->input('nama_rt_rw'),
            'idsubsls' => $request->input('idsubsls'),
            'no_bangunan' => $request->input('no_bangunan'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'nama_kk' => $request->input('nama_kk'),
            'nama_usaha' => $request->input('nama_usaha'),
            'status_data' => 'baru',
            'created_by' => $userId,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $rumahId = DB::transaction(function () use ($data, $userId) {
            $id = DB::table($this->tabelRumah)->insertGetId($data);

            $sesudah = DB::table($this->tabelRumah)
                ->where('id', $id)
                ->first();

            $this->tulisLog(
                $id,
                'tambah',
                null,
                $sesudah,
                $userId,
                'Penambahan data kode rumah'
            );

            return $id;
        });

        return response()->json([
            'success' => true,
            'message' => 'Data kode rumah berhasil ditambahkan.',
            'data' => DB::table($this->tabelRumah)
                ->where('id', $rumahId)
                ->first(),
        ], 201);
    }

    /**
     * PUT /api/kode-rumah/{id}
     * Mengubah data rumah selain retag khusus koordinat.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nama_kecamatan' => 'sometimes|required|string|max:100',
            'nama_kelurahan' => 'sometimes|required|string|max:100',
            'nama_rt_rw' => 'sometimes|required|string|max:100',
            'idsubsls' => 'sometimes|required|string|max:30',
            'no_bangunan' => 'sometimes|required|string|max:20',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'nama_kk' => 'sometimes|nullable|string|max:255',
            'nama_usaha' => 'sometimes|nullable|string|max:255',
        ], [
            'latitude.between' => 'Latitude harus berada antara -90 dan 90.',
            'longitude.between' => 'Longitude harus berada antara -180 dan 180.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data perubahan tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $kolomDiizinkan = [
            'nama_kecamatan',
            'nama_kelurahan',
            'nama_rt_rw',
            'idsubsls',
            'no_bangunan',
            'latitude',
            'longitude',
            'nama_kk',
            'nama_usaha',
        ];

        $perubahan = [];

        foreach ($kolomDiizinkan as $kolom) {
            if ($request->exists($kolom)) {
                $perubahan[$kolom] = $request->input($kolom);
            }
        }

        if (empty($perubahan)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada data yang dikirim untuk diubah.',
            ], 422);
        }

        $userId = $request->user()->id;

        $berhasil = DB::transaction(function () use (
            $id,
            $perubahan,
            $userId
        ) {
            $sebelum = DB::table($this->tabelRumah)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$sebelum) {
                return false;
            }

            $perubahan['status_data'] = 'edited';
            $perubahan['updated_by'] = $userId;
            $perubahan['updated_at'] = now();

            DB::table($this->tabelRumah)
                ->where('id', $id)
                ->update($perubahan);

            $sesudah = DB::table($this->tabelRumah)
                ->where('id', $id)
                ->first();

            $this->tulisLog(
                (int) $id,
                'ubah',
                $sebelum,
                $sesudah,
                $userId,
                'Perubahan data kode rumah'
            );

            return true;
        });

        if (!$berhasil) {
            return response()->json([
                'success' => false,
                'message' => 'Data kode rumah tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data kode rumah berhasil diperbarui.',
            'data' => DB::table($this->tabelRumah)
                ->where('id', $id)
                ->first(),
        ]);
    }

    /**
     * PATCH /api/kode-rumah/{id}/retag
     * Hanya memperbarui latitude dan longitude.
     */
    public function retag(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ], [
            'latitude.required' => 'Latitude lokasi terkini wajib diisi.',
            'latitude.numeric' => 'Latitude harus berupa angka.',
            'latitude.between' => 'Latitude harus berada antara -90 dan 90.',
            'longitude.required' => 'Longitude lokasi terkini wajib diisi.',
            'longitude.numeric' => 'Longitude harus berupa angka.',
            'longitude.between' => 'Longitude harus berada antara -180 dan 180.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Koordinat retag tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user()->id;

        $hasil = DB::transaction(function () use (
            $id,
            $request,
            $userId
        ) {
            $sebelum = DB::table($this->tabelRumah)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$sebelum) {
                return null;
            }

            DB::table($this->tabelRumah)
                ->where('id', $id)
                ->update([
                    'latitude' => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),
                    'status_data' => 'edited',
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);

            $sesudah = DB::table($this->tabelRumah)
                ->where('id', $id)
                ->first();

            $this->tulisLog(
                (int) $id,
                'ubah',
                $sebelum,
                $sesudah,
                $userId,
                'Retag koordinat berdasarkan lokasi terkini'
            );

            return $sesudah;
        });

        if ($hasil === null) {
            return response()->json([
                'success' => false,
                'message' => 'Data kode rumah tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Koordinat rumah berhasil diperbarui.',
            'data' => $hasil,
        ]);
    }

    /**
     * DELETE /api/kode-rumah/{id}
     * Hapus data rumah dengan snapshot log sebelum penghapusan.
     */
    public function destroy(Request $request, $id)
    {
        $userId = $request->user()->id;

        $berhasil = DB::transaction(function () use ($id, $userId) {
            $sebelum = DB::table($this->tabelRumah)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$sebelum) {
                return false;
            }

            $this->tulisLog(
                (int) $id,
                'hapus',
                $sebelum,
                null,
                $userId,
                'Penghapusan data kode rumah'
            );

            DB::table($this->tabelRumah)
                ->where('id', $id)
                ->delete();

            return true;
        });

        if (!$berhasil) {
            return response()->json([
                'success' => false,
                'message' => 'Data kode rumah tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data kode rumah berhasil dihapus.',
        ]);
    }

    /**
     * GET /api/kode-rumah/log
     * Daftar log. Akses dibatasi oleh middleware route.
     */
    public function logs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kode_rumah_id' => 'nullable|integer|min:1',
            'aksi' => 'nullable|in:tambah,ubah,hapus',
            'user_id' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ], [
            'aksi.in' => 'Aksi log harus tambah, ubah, atau hapus.',
            'per_page.max' => 'Maksimal 100 log per halaman.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter log tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = DB::table($this->tabelLog . ' as l')
            ->leftJoin(
                $this->tabelRumah . ' as r',
                'r.id',
                '=',
                'l.kode_rumah_id'
            )
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->select(
                'l.id',
                'l.kode_rumah_id',
                'r.idsubsls',
                'r.no_bangunan',
                'l.aksi',
                'l.data_sebelum',
                'l.data_sesudah',
                'l.user_id',
                'u.name as nama_pengguna',
                'l.keterangan',
                'l.created_at'
            );

        if ($request->filled('kode_rumah_id')) {
            $query->where(
                'l.kode_rumah_id',
                $request->input('kode_rumah_id')
            );
        }

        if ($request->filled('aksi')) {
            $query->where('l.aksi', $request->input('aksi'));
        }

        if ($request->filled('user_id')) {
            $query->where('l.user_id', $request->input('user_id'));
        }

        $hasil = $query
            ->orderByDesc('l.created_at')
            ->orderByDesc('l.id')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'message' => 'Riwayat kode rumah berhasil diambil.',
            'data' => $hasil->items(),
            'pagination' => [
                'current_page' => $hasil->currentPage(),
                'per_page' => $hasil->perPage(),
                'total' => $hasil->total(),
                'last_page' => $hasil->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/kode-rumah/log/{id}
     * Detail satu log.
     */
    public function showLog($id)
    {
        $log = DB::table($this->tabelLog . ' as l')
            ->leftJoin(
                $this->tabelRumah . ' as r',
                'r.id',
                '=',
                'l.kode_rumah_id'
            )
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.id', $id)
            ->select(
                'l.id',
                'l.kode_rumah_id',
                'r.idsubsls',
                'r.no_bangunan',
                'l.aksi',
                'l.data_sebelum',
                'l.data_sesudah',
                'l.user_id',
                'u.name as nama_pengguna',
                'l.keterangan',
                'l.created_at'
            )
            ->first();

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Riwayat kode rumah tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail riwayat berhasil diambil.',
            'data' => $log,
        ]);
    }

    /**
     * Simpan riwayat perubahan.
     * Dipanggil di dalam transaksi perubahan data.
     */
    private function tulisLog(
        ?int $rumahId,
        string $aksi,
        $sebelum,
        $sesudah,
        ?int $userId,
        ?string $keterangan
    ): void {
        DB::table($this->tabelLog)->insert([
            'kode_rumah_id' => $rumahId,
            'aksi' => $aksi,
            'data_sebelum' => $sebelum === null
                ? null
                : json_encode(
                    $sebelum,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            'data_sesudah' => $sesudah === null
                ? null
                : json_encode(
                    $sesudah,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            'user_id' => $userId,
            'keterangan' => $keterangan,
            'created_at' => now(),
        ]);
    }
}
