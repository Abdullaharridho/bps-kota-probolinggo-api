<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuratMasukResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'nomor_surat' => $this->nomor_surat,

            'tanggal_surat' => $this->tanggal_surat
                ? $this->tanggal_surat->format('Y-m-d')
                : null,

            'tanggal_diterima' => $this->tanggal_diterima
                ? $this->tanggal_diterima->format('Y-m-d')
                : null,

            'asal_surat' => $this->asal_surat,

            'perihal' => $this->perihal,

            'isi_ringkas' => $this->isi_ringkas,

            // Field acara dalam bentuk flat.
            'tanggal_acara' => $this->tanggal_acara
                ? $this->tanggal_acara->format('Y-m-d')
                : null,

            'waktu_mulai' => $this->waktu_mulai
                ? \Carbon\Carbon::parse($this->waktu_mulai)->format('H:i')
                : null,

            'waktu_selesai' => $this->waktu_selesai
                ? \Carbon\Carbon::parse($this->waktu_selesai)->format('H:i')
                : null,

            'lokasi' => $this->lokasi,

            // Tetap mempertahankan struktur acara.
            'acara' => [
                'tanggal' => $this->tanggal_acara
                    ? $this->tanggal_acara->format('Y-m-d')
                    : null,

                'waktu_mulai' => $this->waktu_mulai
                    ? \Carbon\Carbon::parse($this->waktu_mulai)->format('H:i')
                    : null,

                'waktu_selesai' => $this->waktu_selesai
                    ? \Carbon\Carbon::parse($this->waktu_selesai)->format('H:i')
                    : null,

                'lokasi' => $this->lokasi,
            ],

            'file_url' => $this->file_surat
                ? asset('storage/' . $this->file_surat)
                : null,

            'status' => $this->status,

            // Informasi tim yang ditugaskan.
            'tim_id' => $this->tim_id,

            'nama_tim' => $this->nama_tim ?? null,

            // Informasi ketua tim.
            'ketua_id' => $this->ketua_id ?? null,

            'nama_ketua' => $this->nama_ketua ?? null,

            'hp_ketua' => $this->hp_ketua ?? null,

            // Informasi operator yang mencatat surat.
            'pencatat' => [
                'id' => $this->dicatat_oleh,

                'nama' => $this->whenLoaded(
                    'dicatatOleh',
                    fn () => $this->dicatatOleh->name
                ),
            ],

            'dibuat_pada' => $this->created_at
                ? $this->created_at->format('Y-m-d H:i:s')
                : null,
        ];
    }
}
