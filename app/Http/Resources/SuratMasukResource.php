<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SuratMasukResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomor_surat' => $this->nomor_surat,
            'tanggal_surat' => $this->tanggal_surat->format('Y-m-d'),
            'tanggal_diterima' => $this->tanggal_diterima->format('Y-m-d'),
            'asal_surat' => $this->asal_surat,
            'perihal' => $this->perihal,
            'isi_ringkas' => $this->isi_ringkas,
            'acara' => [
                'tanggal' => $this->tanggal_acara ? $this->tanggal_acara->format('Y-m-d') : null,
                'waktu_mulai' => $this->waktu_mulai ? \Carbon\Carbon::parse($this->waktu_mulai)->format('H:i') : null,
                'waktu_selesai' => $this->waktu_selesai ? \Carbon\Carbon::parse($this->waktu_selesai)->format('H:i') : null,
                'lokasi' => $this->lokasi,
            ],
            'file_url' => $this->file_surat ? asset('storage/' . $this->file_surat) : null,
            'status' => $this->status,
            'pencatat' => [
                'id' => $this->dicatat_oleh,
                'nama' => $this->whenLoaded('dicatatOleh', fn() => $this->dicatatOleh->name),
            ],
            'dibuat_pada' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
