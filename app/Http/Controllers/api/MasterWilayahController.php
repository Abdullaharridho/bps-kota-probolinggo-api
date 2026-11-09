<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class MasterWilayahController extends Controller
{
    /**
     * Konfigurasi tiga tingkat master wilayah.
     * Nama tabel dan kolom ditentukan di server, bukan dari input bebas.
     */
    private function config(string $level): array
    {
        $configs = [
            'kecamatan' => [
                'table' => 'master_kecamatan',
                'code' => 'kode_kecamatan',
                'name' => 'nama_kecamatan',
                'parent' => null,
                'parent_label' => null,
                'child_table' => 'master_kelurahan',
                'child_fk' => 'kecamatan_id',
                'child_count' => 'jumlah_kelurahan',
                'unique_scope' => null,
            ],
            'kelurahan' => [
                'table' => 'master_kelurahan',
                'code' => 'kode_kelurahan',
                'name' => 'nama_kelurahan',
                'parent' => 'kecamatan_id',
                'parent_label' => 'Kecamatan',
                'child_table' => 'master_rtrw',
                'child_fk' => 'kelurahan_id',
                'child_count' => 'jumlah_rtrw',
                'unique_scope' => 'kecamatan_id',
            ],
            'rtrw' => [
                'table' => 'master_rtrw',
                'code' => 'kode_wilayah',
                'name' => 'nama_wilayah',
                'parent' => 'kelurahan_id',
                'parent_label' => 'Kelurahan',
                'child_table' => null,
                'child_fk' => null,
                'child_count' => null,
                'unique_scope' => 'kelurahan_id',
            ],
        ];

        abort_unless(isset($configs[$level]), 404, 'Tingkat wilayah tidak ditemukan.');

        return $configs[$level];
    }

    /**
     * Semua operasi tulis hanya boleh dilakukan Super Admin.
     * Route mutasi juga wajib menggunakan auth:sanctum.
     */
    private function authorizeSuperAdmin(): void
    {
        abort_unless(
            auth()->user()?->role === 'super_admin',
            403,
            'Akses hanya untuk Super Admin.'
        );
    }

    /**
     * GET daftar wilayah.
     *
     * Parameter:
     * q        = pencarian kode/nama
     * status   = semua|aktif|nonaktif
     * sort     = nama|kode|terbaru
     * order    = asc|desc
     * per_page = 1-100
     * page     = nomor halaman
     *
     * Untuk kelurahan: kecamatan_id (opsional).
     * Untuk rtrw: kelurahan_id (opsional).
     */
    public function index(Request $request, string $level)
    {
        $config = $this->config($level);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:semua,aktif,nonaktif'],
            'sort' => ['nullable', 'in:nama,kode,terbaru'],
            'order' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'kecamatan_id' => [
                'nullable',
                'integer',
                'min:1',
                'required_if:level,kelurahan',
            ],
            'kelurahan_id' => [
                'nullable',
                'integer',
                'min:1',
                'required_if:level,rtrw',
            ],
        ]);

        $query = DB::table($config['table']);

        // Jumlah anak untuk ditampilkan pada setiap card.
        if ($config['child_table']) {
            $query->leftJoinSub(
                DB::table($config['child_table'])
                    ->select($config['child_fk'])
                    ->selectRaw('COUNT(*) as total')
                    ->groupBy($config['child_fk']),
                'child_counts',
                $config['table'] . '.id',
                '=',
                'child_counts.' . $config['child_fk']
            );

            $query->addSelect(
                $config['table'] . '.*',
                DB::raw(
                    'COALESCE(child_counts.total, 0) as ' .
                    $config['child_count']
                )
            );
        } else {
            $query->select($config['table'] . '.*');
        }

        // Filter status. Default semua agar GET publik menampilkan semua data.
        $status = $validated['status'] ?? 'semua';

        if ($status === 'aktif') {
            $query->where($config['table'] . '.is_active', true);
        } elseif ($status === 'nonaktif') {
            $query->where($config['table'] . '.is_active', false);
        }

        // Pencarian kode atau nama.
        if (!empty($validated['q'])) {
            $keyword = trim($validated['q']);

            $query->where(function ($q) use ($config, $keyword) {
                $q->where(
                    $config['table'] . '.' . $config['code'],
                    'like',
                    '%' . $keyword . '%'
                )->orWhere(
                    $config['table'] . '.' . $config['name'],
                    'like',
                    '%' . $keyword . '%'
                );
            });
        }

        // Filter induk agar daftar mengikuti wilayah yang dipilih.
        if ($level === 'kelurahan' && !empty($validated['kecamatan_id'])) {
            $query->where(
                $config['table'] . '.kecamatan_id',
                $validated['kecamatan_id']
            );
        }

        if ($level === 'rtrw' && !empty($validated['kelurahan_id'])) {
            $query->where(
                $config['table'] . '.kelurahan_id',
                $validated['kelurahan_id']
            );
        }

        $sortColumn = match ($validated['sort'] ?? 'nama') {
            'kode' => $config['code'],
            'terbaru' => 'created_at',
            default => $config['name'],
        };

        $query->orderBy(
            $config['table'] . '.' . $sortColumn,
            $validated['order'] ?? 'asc'
        );

        // ID menjadi pengurutan sekunder yang stabil.
        $query->orderBy($config['table'] . '.id');

        $data = $query->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'message' => 'Data ' . $level . ' berhasil diambil.',
            'data' => $data->items(),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
            ],
            'filters' => [
                'q' => $validated['q'] ?? '',
                'status' => $status,
                'sort' => $validated['sort'] ?? 'nama',
                'order' => $validated['order'] ?? 'asc',
            ],
        ]);
    }

    /**
     * GET detail satu wilayah.
     */
    public function show(string $level, int $id)
    {
        $config = $this->config($level);

        $query = DB::table($config['table'])
            ->where($config['table'] . '.id', $id);

        if ($config['child_table']) {
            $query->leftJoinSub(
                DB::table($config['child_table'])
                    ->select($config['child_fk'])
                    ->selectRaw('COUNT(*) as total')
                    ->groupBy($config['child_fk']),
                'child_counts',
                $config['table'] . '.id',
                '=',
                'child_counts.' . $config['child_fk']
            );

            $query->addSelect(
                $config['table'] . '.*',
                DB::raw(
                    'COALESCE(child_counts.total, 0) as ' .
                    $config['child_count']
                )
            );
        } else {
            $query->select($config['table'] . '.*');
        }

        $data = $query->first();

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => 'Data wilayah tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail wilayah berhasil diambil.',
            'data' => $data,
        ]);
    }

    /**
     * POST tambah wilayah.
     */
    public function store(Request $request, string $level)
    {
        $this->authorizeSuperAdmin();

        $config = $this->config($level);
        $data = $this->validateData($request, $level, $config);

        $now = now();

        $insert = [
            $config['code'] => trim($data[$config['code']]),
            $config['name'] => trim($data[$config['name']]),
            'is_active' => $data['is_active'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($config['parent']) {
            $insert[$config['parent']] = $data[$config['parent']];
        }

        $id = DB::table($config['table'])->insertGetId($insert);

        return response()->json([
            'success' => true,
            'message' => 'Data ' . $level . ' berhasil ditambahkan.',
            'data' => DB::table($config['table'])->where('id', $id)->first(),
        ], 201);
    }

    /**
     * PUT/PATCH ubah wilayah.
     */
    public function update(Request $request, string $level, int $id)
    {
        $this->authorizeSuperAdmin();

        $config = $this->config($level);

        $existing = DB::table($config['table'])->where('id', $id)->first();

        if (!$existing) {
            return response()->json([
                'success' => false,
                'message' => 'Data wilayah tidak ditemukan.',
            ], 404);
        }

        $data = $this->validateData($request, $level, $config, $id);

        $update = [
            $config['code'] => trim($data[$config['code']]),
            $config['name'] => trim($data[$config['name']]),
            'is_active' => $data['is_active'] ?? $existing->is_active,
            'updated_at' => now(),
        ];

        if ($config['parent']) {
            $update[$config['parent']] = $data[$config['parent']];
        }

        DB::table($config['table'])->where('id', $id)->update($update);

        return response()->json([
            'success' => true,
            'message' => 'Data ' . $level . ' berhasil diperbarui.',
            'data' => DB::table($config['table'])->where('id', $id)->first(),
        ]);
    }

    /**
     * DELETE hapus wilayah.
     *
     * Foreign key cascade yang sudah ada akan menghapus data turunan.
     */
    public function destroy(string $level, int $id)
    {
        $this->authorizeSuperAdmin();

        $config = $this->config($level);

        $existing = DB::table($config['table'])->where('id', $id)->first();

        if (!$existing) {
            return response()->json([
                'success' => false,
                'message' => 'Data wilayah tidak ditemukan.',
            ], 404);
        }

        try {
            DB::transaction(function () use ($config, $id) {
                DB::table($config['table'])->where('id', $id)->delete();
            });
        } catch (QueryException $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Data wilayah gagal dihapus karena masih digunakan oleh data lain.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data ' . $level . ' berhasil dihapus.',
        ]);
    }

    /**
     * Validasi input dan kode unik.
     */
    private function validateData(
        Request $request,
        string $level,
        array $config,
        ?int $ignoreId = null
    ): array {
        $rules = [
            $config['code'] => ['required', 'string', 'max:'
                . ($level === 'rtrw' ? '50' : '30')],
            $config['name'] => ['required', 'string', 'max:'
                . ($level === 'rtrw' ? '150' : '100')],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($config['parent']) {
            $rules[$config['parent']] = [
                'required',
                'integer',
                'min:1',
            ];
        }

        $messages = [
            $config['code'] . '.required' => 'Kode wilayah wajib diisi.',
            $config['code'] . '.string' => 'Kode wilayah harus berupa teks.',
            $config['code'] . '.max' => 'Kode wilayah terlalu panjang.',
            $config['name'] . '.required' => 'Nama wilayah wajib diisi.',
            $config['name'] . '.string' => 'Nama wilayah harus berupa teks.',
            $config['name'] . '.max' => 'Nama wilayah terlalu panjang.',
            'is_active.boolean' => 'Status aktif harus bernilai true atau false.',
        ];

        if ($config['parent']) {
            $messages[$config['parent'] . '.required'] =
                $config['parent_label'] . ' wajib dipilih.';
            $messages[$config['parent'] . '.integer'] =
                $config['parent_label'] . ' tidak valid.';
        }

        $data = Validator::make($request->all(), $rules, $messages)->validate();

        // Pastikan induk benar-benar ada.
        if ($config['parent']) {
            $parentTable = $level === 'kelurahan'
                ? 'master_kecamatan'
                : 'master_kelurahan';

            $parentExists = DB::table($parentTable)
                ->where('id', $data[$config['parent']])
                ->exists();

            if (!$parentExists) {
                throw ValidationException::withMessages([
                    $config['parent'] => [
                        $config['parent_label'] . ' yang dipilih tidak ditemukan.',
                    ],
                ]);
            }
        }

        // Periksa keunikan kode sesuai cakupan pada migration.
        $uniqueQuery = DB::table($config['table'])
            ->where($config['code'], trim($data[$config['code']]));

        if ($config['unique_scope']) {
            $uniqueQuery->where(
                $config['unique_scope'],
                $data[$config['unique_scope']]
            );
        }

        if ($ignoreId !== null) {
            $uniqueQuery->where('id', '<>', $ignoreId);
        }

        if ($uniqueQuery->exists()) {
            throw ValidationException::withMessages([
                $config['code'] => [
                    'Kode wilayah sudah digunakan pada wilayah induk tersebut.',
                ],
            ]);
        }

        return $data;
    }
}