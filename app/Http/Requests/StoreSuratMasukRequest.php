<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSuratMasukRequest extends FormRequest
{
    /**
     * Tentukan apakah user memiliki izin untuk melakukan request ini.
     */
    public function authorize(): bool
    {
        // Otomatis true karena middleware Sanctum sudah melindungi rutenya.
        // Opsional: Anda bisa tambahkan logika pengecekan role 'operator'.
        return true;
    }

    /**
     * Aturan validasi ketat untuk endpoint Surat Masuk.
     */
    public function rules(): array
    {
        return [
            'nomor_surat' => ['required', 'string', 'max:255'],
            'tanggal_surat' => ['required', 'date'],
            'tanggal_diterima' => ['required', 'date'],
            'asal_surat' => ['required', 'string', 'max:255'],
            'perihal' => ['required', 'string', 'max:255'],
            'isi_ringkas' => ['nullable', 'string'],
            'tanggal_acara' => ['nullable', 'date'],
            'waktu_mulai' => ['nullable', 'date_format:H:i'],
            'waktu_selesai' => ['nullable', 'date_format:H:i', 'after:waktu_mulai'],
            'lokasi' => ['nullable', 'string', 'max:255'],
            // Validasi file: maksimal 2MB (2048 KB)
            'file_surat' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:2048'],
            'status' => ['nullable', 'in:baru,diproses,selesai,dibatalkan'],
        ];
    }
}
