<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSuratMasukRequest;
use App\Http\Resources\SuratMasukResource;
use App\Models\SuratMasuk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SuratMasukController extends Controller
{
    /**
     * Mendapatkan Daftar Surat Masuk
     *
     * Endpoint ini mengembalikan semua data surat masuk, diurutkan dari yang terbaru.
     */
    public function index(): AnonymousResourceCollection
    {
        $suratMasuk = SuratMasuk::with('dicatatOleh')->latest()->get();

        return SuratMasukResource::collection($suratMasuk);
    }
    public function store(StoreSuratMasukRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['dicatat_oleh'] = $request->user()->id;
        $validated['status'] = $request->status ?? 'baru';

        // Keamanan: Handling File Upload yang aman
        if ($request->hasFile('file_surat')) {
            $path = $request->file('file_surat')->store('surat_masuk', 'public');
            $validated['file_surat'] = $path;
        }

        $suratMasuk = SuratMasuk::create($validated);
        $suratMasuk->load('dicatatOleh');

        return response()->json([
            'status' => 'success',
            'message' => 'Surat masuk berhasil ditambahkan.',
            'data' => new SuratMasukResource($suratMasuk)
        ], 201);
    }

    /**
     * Melihat Detail Surat Masuk
     *
     * Endpoint untuk menampilkan detail spesifik dari satu surat masuk berdasarkan ID.
     */
    public function show(string $id): JsonResponse
    {
        $suratMasuk = SuratMasuk::with('dicatatOleh')->find($id);

        if (!$suratMasuk) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data surat masuk tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Detail surat masuk berhasil diambil.',
            'data' => new SuratMasukResource($suratMasuk)
        ], 200);
    }
}
