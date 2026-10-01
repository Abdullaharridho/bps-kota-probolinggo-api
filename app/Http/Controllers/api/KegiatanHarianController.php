<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;
use Throwable;

class KegiatanHarianController extends Controller
{
    /**
     * Menampilkan daftar kegiatan milik pegawai yang login.
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $query = DB::table('tb_kegiatan_harian')
                ->where('user_id', $userId);

            if ($request->filled('tanggal')) {
                $validator = Validator::make(
                    $request->only('tanggal'),
                    [
                        'tanggal' => 'required|date_format:Y-m-d',
                    ],
                    [
                        'tanggal.required' => 'Tanggal wajib diisi.',
                        'tanggal.date_format' =>
                            'Format tanggal harus YYYY-MM-DD.',
                    ]
                );

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tanggal yang dikirim tidak valid.',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $query->where('tanggal', $request->tanggal);
            }

            $data = $query
                ->orderByDesc('tanggal')
                ->orderBy('jam_mulai')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Daftar kegiatan berhasil dimuat.',
                'data' => $data,
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar kegiatan.',
            ], 500);
        }
    }

    /**
     * Menyimpan kegiatan baru.
     * Tanggal ditentukan otomatis oleh server.
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'nama_kegiatan' => 'required|string|max:255',
                'jam_mulai' => 'required|date_format:H:i',
                'jam_selesai' => [
                    'required',
                    'date_format:H:i',
                    'after:jam_mulai',
                ],
            ],
            [
                'nama_kegiatan.required' =>
                    'Nama kegiatan wajib diisi.',
                'nama_kegiatan.string' =>
                    'Nama kegiatan harus berupa teks.',
                'nama_kegiatan.max' =>
                    'Nama kegiatan maksimal 255 karakter.',

                'jam_mulai.required' =>
                    'Jam mulai wajib diisi.',
                'jam_mulai.date_format' =>
                    'Format jam mulai harus HH:MM, contoh 08:30.',

                'jam_selesai.required' =>
                    'Jam selesai wajib diisi.',
                'jam_selesai.date_format' =>
                    'Format jam selesai harus HH:MM, contoh 10:30.',
                'jam_selesai.after' =>
                    'Jam selesai harus lebih besar daripada jam mulai.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data kegiatan tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $userId = $request->user()->id;

            $tanggal = now(config('app.timezone', 'Asia/Jakarta'))
                ->toDateString();

            $id = DB::table('tb_kegiatan_harian')->insertGetId([
                'user_id' => $userId,
                'tanggal' => $tanggal,
                'nama_kegiatan' => trim($request->nama_kegiatan),
                'jam_mulai' => $request->jam_mulai,
                'jam_selesai' => $request->jam_selesai,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $data = DB::table('tb_kegiatan_harian')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Kegiatan harian berhasil ditambahkan.',
                'data' => $data,
            ], 201);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan kegiatan.',
            ], 500);
        }
    }

    /**
     * Menampilkan detail kegiatan milik pegawai yang login.
     */
    public function show(Request $request, $id)
    {
        try {
            $data = DB::table('tb_kegiatan_harian')
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kegiatan tidak ditemukan.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Detail kegiatan berhasil dimuat.',
                'data' => $data,
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil detail kegiatan.',
            ], 500);
        }
    }

    /**
     * Mengubah nama kegiatan dan jam.
     * Tanggal serta pemilik kegiatan tidak dapat diubah.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'nama_kegiatan' => 'required|string|max:255',
                'jam_mulai' => 'required|date_format:H:i',
                'jam_selesai' => [
                    'required',
                    'date_format:H:i',
                    'after:jam_mulai',
                ],
            ],
            [
                'nama_kegiatan.required' =>
                    'Nama kegiatan wajib diisi.',
                'nama_kegiatan.string' =>
                    'Nama kegiatan harus berupa teks.',
                'nama_kegiatan.max' =>
                    'Nama kegiatan maksimal 255 karakter.',

                'jam_mulai.required' =>
                    'Jam mulai wajib diisi.',
                'jam_mulai.date_format' =>
                    'Format jam mulai harus HH:MM, contoh 08:30.',

                'jam_selesai.required' =>
                    'Jam selesai wajib diisi.',
                'jam_selesai.date_format' =>
                    'Format jam selesai harus HH:MM, contoh 10:30.',
                'jam_selesai.after' =>
                    'Jam selesai harus lebih besar daripada jam mulai.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data perubahan kegiatan tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $userId = $request->user()->id;

            $kegiatan = DB::table('tb_kegiatan_harian')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$kegiatan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kegiatan tidak ditemukan.',
                ], 404);
            }

            DB::table('tb_kegiatan_harian')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->update([
                    'nama_kegiatan' => trim($request->nama_kegiatan),
                    'jam_mulai' => $request->jam_mulai,
                    'jam_selesai' => $request->jam_selesai,
                    'updated_at' => now(),
                ]);

            $data = DB::table('tb_kegiatan_harian')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Kegiatan harian berhasil diperbarui.',
                'data' => $data,
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui kegiatan.',
            ], 500);
        }
    }

    /**
     * Menghapus kegiatan milik pegawai yang login.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $kegiatan = DB::table('tb_kegiatan_harian')
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$kegiatan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kegiatan tidak ditemukan.',
                ], 404);
            }

            DB::table('tb_kegiatan_harian')
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Kegiatan harian berhasil dihapus.',
            ], 200);

        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus kegiatan.',
            ], 500);
        }
    }
}