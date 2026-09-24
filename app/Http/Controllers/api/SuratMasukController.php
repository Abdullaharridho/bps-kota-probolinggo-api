<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSuratMasukRequest;
use App\Http\Resources\SuratMasukResource;
use App\Models\SuratMasuk;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SuratMasukController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SuratMasuk::with('dicatatOleh');

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
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
            'data' => SuratMasukResource::collection($query->latest()->get())
        ]);
    }

    public function store(StoreSuratMasukRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['dicatat_oleh'] = $request->user()->id;
        $validated['status'] = $request->status ?? 'baru';

        if ($request->hasFile('file_surat')) {
            $validated['file_surat'] = $request->file('file_surat')->store('surat_masuk', 'public');
        }

        $suratMasuk = SuratMasuk::create($validated);
        $suratMasuk->load('dicatatOleh');

        return response()->json([
            'status' => 'success',
            'message' => 'Surat masuk berhasil ditambahkan.',
            'data' => new SuratMasukResource($suratMasuk)
        ], 201);
    }


    public function show(string $id): JsonResponse
    {
        $suratMasuk = SuratMasuk::with('dicatatOleh')->find($id);

        if (!$suratMasuk) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Detail surat masuk berhasil diambil.',
            'data' => new SuratMasukResource($suratMasuk)
        ], 200);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $suratMasuk = SuratMasuk::find($id);

        if (!$suratMasuk) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'nomor_surat' => ['sometimes', 'string', Rule::unique('tb_surat_masuk', 'nomor_surat')->ignore($id)],
            'perihal' => 'sometimes|string',
            'asal_surat' => 'sometimes|string',
            'file_surat' => 'nullable|mimes:pdf,jpg,png|max:2048'
        ]);

        if ($request->hasFile('file_surat')) {
            if ($suratMasuk->file_surat && Storage::disk('public')->exists($suratMasuk->file_surat)) {
                Storage::disk('public')->delete($suratMasuk->file_surat);
            }
            $validated['file_surat'] = $request->file('file_surat')->store('surat_masuk', 'public');
        }

        $suratMasuk->update($validated);
        $suratMasuk->load('dicatatOleh');

        return response()->json([
            'status' => 'success',
            'message' => 'Data berhasil diubah.',
            'data' => new SuratMasukResource($suratMasuk)
        ]);
    }


    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $suratMasuk = SuratMasuk::find($id);

        if (!$suratMasuk) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan.'], 404);
        }

        $request->validate(['status' => 'required|string']);
        $suratMasuk->update(['status' => $request->status]);

        return response()->json([
            'status' => 'success',
            'message' => 'Status surat berhasil diubah.'
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $suratMasuk = SuratMasuk::find($id);

        if (!$suratMasuk) {
            return response()->json(['status' => 'error', 'message' => 'Data tidak ditemukan.'], 404);
        }

        if ($suratMasuk->file_surat && Storage::disk('public')->exists($suratMasuk->file_surat)) {
            Storage::disk('public')->delete($suratMasuk->file_surat);
        }

        $suratMasuk->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Surat berhasil dihapus.'
        ]);
    }
}
