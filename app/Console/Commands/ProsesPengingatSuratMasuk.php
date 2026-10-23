<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProsesPengingatSuratMasuk extends Command
{
    protected $signature = 'surat-masuk:proses-pengingat';

    protected $description =
    'Membuat dan mengirim pengingat acara surat masuk melalui WhatsApp';

    public function handle(): int
    {
        $this->info('Memulai proses pengingat surat masuk...');

        try {
            // 1. Membuat atau menyesuaikan jadwal pengingat.
            $this->buatJadwalPengingat();

            // 2. Mengirim pengingat yang sudah jatuh tempo.
            $this->kirimPengingatJatuhTempo();

            $this->info('Proses pengingat selesai.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Proses pengingat surat masuk gagal.', [
                'error' => $e->getMessage(),
            ]);

            $this->error('Proses gagal: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Membuat jadwal pengingat H-1 dan H-1 jam.
     *
     * Penerima hanya pegawai yang penugasannya berstatus diterima.
     */
    private function buatJadwalPengingat(): void
    {
        $sekarang = now();
        $awalMenitIni = $sekarang->copy()->startOfMinute();

        $daftarPenugasan = DB::table('tb_penugasan as p')
            ->join(
                'tb_surat_masuk as s',
                's.id',
                '=',
                'p.surat_masuk_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                'p.ditugaskan_kepada'
            )
            ->where('p.status', 'diterima')
            ->where('u.status', 'aktif')
            ->whereNotNull('s.tanggal_acara')
            ->whereNotNull('s.waktu_mulai')
            ->whereNotIn('s.status', [
                'selesai',
                'dibatalkan',
            ])
            ->select([
                'p.id as penugasan_id',
                'p.ditugaskan_kepada as pengguna_id',
                's.id as surat_masuk_id',
                's.nomor_surat',
                's.perihal',
                's.tanggal_acara',
                's.waktu_mulai',
                's.waktu_selesai',
                's.lokasi',
            ])
            ->get();

        foreach ($daftarPenugasan as $penugasan) {
            try {
                $waktuAcara = Carbon::parse(
                    $penugasan->tanggal_acara . ' ' .
                        $penugasan->waktu_mulai,
                    config('app.timezone', 'Asia/Jakarta')
                );

                // Acara yang sudah berlangsung tidak diberi jadwal baru.
                if ($waktuAcara->lessThanOrEqualTo($sekarang)) {
                    continue;
                }

                $daftarPengingat = [
                    [
                        'jenis' => 'reminder_h1',
                        'judul' => 'Pengingat Acara Besok',
                        'jadwal' => $waktuAcara->copy()->subDay(),
                        'label' => 'H-1',
                    ],
                    [
                        'jenis' => 'reminder_h',
                        'judul' => 'Pengingat Acara Satu Jam Lagi',
                        'jadwal' => $waktuAcara->copy()->subHour(),
                        'label' => 'H-1 jam',
                    ],
                ];

                foreach ($daftarPengingat as $pengingat) {
                    $this->simpanJadwalPengingat(
                        $penugasan,
                        $pengingat,
                        $awalMenitIni
                    );
                }
            } catch (Throwable $e) {
                Log::error(
                    'Gagal membuat jadwal pengingat penugasan.',
                    [
                        'penugasan_id' => $penugasan->penugasan_id,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    /**
     * Membuat jadwal baru atau memperbarui jadwal yang masih pending.
     */
    private function simpanJadwalPengingat(
        object $penugasan,
        array $pengingat,
        Carbon $awalMenitIni
    ): void {
        $jadwalBaru = $pengingat['jadwal'];

        $notifikasi = DB::table('tb_notifikasi')
            ->where('pengguna_id', $penugasan->pengguna_id)
            ->where('jenis', $pengingat['jenis'])
            ->where('referensi_tipe', 'penugasan')
            ->where('referensi_id', $penugasan->penugasan_id)
            ->first();

        $pesan = $this->buatPesanPengingat(
            $penugasan,
            $pengingat['label'],
            $jadwalBaru
        );

        if ($notifikasi) {
            // Jangan mengubah notifikasi yang sudah terkirim.
            if ($notifikasi->status !== 'pending') {
                return;
            }

            $jadwalLama = $notifikasi->jadwal_kirim
                ? Carbon::parse(
                    $notifikasi->jadwal_kirim,
                    config('app.timezone', 'Asia/Jakarta')
                )
                : null;

            // Jadwal belum berubah, biarkan proses pengiriman
            // menangani notifikasi yang sudah jatuh tempo.
            if (
                $jadwalLama &&
                $jadwalLama->equalTo($jadwalBaru)
            ) {
                return;
            }

            // Jadwal baru sudah terlewat akibat perubahan acara.
            // Jangan mengirim pengingat pada waktu yang salah.
            if ($jadwalBaru->lessThan($awalMenitIni)) {
                DB::table('tb_notifikasi')
                    ->where('id', $notifikasi->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'failed',
                        'gagal_pada' => now(),
                        'pesan_error' =>
                        'Pengingat dilewati karena jadwal acara berubah ' .
                            'dan waktu pengingat yang baru sudah terlewat.',
                        'updated_at' => now(),
                    ]);

                return;
            }

            // Jadwal acara berubah, perbarui notifikasi yang belum terkirim.
            DB::table('tb_notifikasi')
                ->where('id', $notifikasi->id)
                ->where('status', 'pending')
                ->update([
                    'judul' => $pengingat['judul'],
                    'pesan' => $pesan,
                    'jadwal_kirim' => $jadwalBaru,
                    'pesan_error' => null,
                    'updated_at' => now(),
                ]);

            return;
        }

        // Jangan membuat pengingat baru jika waktu kirimnya sudah terlewat.
        if ($jadwalBaru->lessThan($awalMenitIni)) {
            return;
        }

        DB::table('tb_notifikasi')->insert([
            'pengguna_id' => $penugasan->pengguna_id,
            'jenis' => $pengingat['jenis'],
            'channel' => 'whatsapp',
            'judul' => $pengingat['judul'],
            'pesan' => $pesan,
            'referensi_tipe' => 'penugasan',
            'referensi_id' => $penugasan->penugasan_id,
            'status' => 'pending',
            'jadwal_kirim' => $jadwalBaru,
            'terkirim_pada' => null,
            'gagal_pada' => null,
            'pesan_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Membentuk isi pesan WhatsApp.
     */
    private function buatPesanPengingat(
        object $penugasan,
        string $label,
        Carbon $waktuAcara
    ): string {
        $baris = [
            '🔔 PENGINGAT ACARA BPSKp',
            '',
            'Pengingat: ' . $label,
            'Nomor surat: ' . $penugasan->nomor_surat,
            'Perihal: ' . $penugasan->perihal,
            'Tanggal acara: ' . $waktuAcara->format('d-m-Y'),
            'Waktu mulai: ' . $waktuAcara->format('H:i'),
        ];

        if (!empty($penugasan->waktu_selesai)) {
            $baris[] = 'Waktu selesai: ' .
                Carbon::parse($penugasan->waktu_selesai)
                ->format('H:i');
        }

        if (!empty($penugasan->lokasi)) {
            $baris[] = 'Lokasi: ' . $penugasan->lokasi;
        }

        $baris[] = '';
        $baris[] = 'Silakan periksa detail penugasan melalui aplikasi BPSKp.';

        return implode("\n", $baris);
    }

    /**
     * Mengirim notifikasi WhatsApp yang sudah jatuh tempo.
     */
    private function kirimPengingatJatuhTempo(): void
    {
        $token = config('services.fonnte.token');
        $url = config(
            'services.fonnte.url',
            'https://api.fonnte.com/send'
        );

        if (empty($token) || empty($url)) {
            Log::warning(
                'Pengingat tidak dikirim karena konfigurasi Fonnte belum tersedia.'
            );

            return;
        }

        $daftarNotifikasi = DB::table('tb_notifikasi')
            ->where('channel', 'whatsapp')
            ->whereIn('jenis', [
                'reminder_h1',
                'reminder_h',
            ])
            ->where('status', 'pending')
            ->whereNotNull('jadwal_kirim')
            ->where('jadwal_kirim', '<=', now())
            ->orderBy('jadwal_kirim')
            ->limit(50)
            ->get();

        foreach ($daftarNotifikasi as $notifikasi) {
            // Klaim notifikasi agar proses lain tidak ikut mengirimnya.
            $berhasilKlaim = DB::table('tb_notifikasi')
                ->where('id', $notifikasi->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'processing',
                    'updated_at' => now(),
                ]);

            if ($berhasilKlaim !== 1) {
                continue;
            }

            try {
                // Pastikan acara masih relevan dan penugasan masih diterima.
                $dataAcara = DB::table('tb_penugasan as p')
                    ->join(
                        'tb_surat_masuk as s',
                        's.id',
                        '=',
                        'p.surat_masuk_id'
                    )
                    ->where('p.id', $notifikasi->referensi_id)
                    ->where(
                        'p.ditugaskan_kepada',
                        $notifikasi->pengguna_id
                    )
                    ->where('p.status', 'diterima')
                    ->whereNotIn('s.status', [
                        'selesai',
                        'dibatalkan',
                    ])
                    ->whereNotNull('s.tanggal_acara')
                    ->whereNotNull('s.waktu_mulai')
                    ->select([
                        's.tanggal_acara',
                        's.waktu_mulai',
                    ])
                    ->first();

                if (!$dataAcara) {
                    $this->tandaiGagal(
                        $notifikasi->id,
                        'Pengingat dilewati karena penugasan tidak lagi aktif.'
                    );

                    continue;
                }

                $waktuAcara = Carbon::parse(
                    $dataAcara->tanggal_acara . ' ' .
                        $dataAcara->waktu_mulai,
                    config('app.timezone', 'Asia/Jakarta')
                );

                // Jangan mengirim pengingat untuk acara yang sudah dimulai.
                if ($waktuAcara->lessThanOrEqualTo(now())) {
                    $this->tandaiGagal(
                        $notifikasi->id,
                        'Pengingat dilewati karena acara sudah dimulai.'
                    );

                    continue;
                }

                $pengguna = DB::table('users')
                    ->where('id', $notifikasi->pengguna_id)
                    ->where('status', 'aktif')
                    ->select(['id', 'no_hp'])
                    ->first();

                if (
                    !$pengguna ||
                    empty(trim((string) $pengguna->no_hp))
                ) {
                    $this->tandaiGagal(
                        $notifikasi->id,
                        'Nomor WhatsApp penerima tidak tersedia atau pengguna tidak aktif.'
                    );

                    continue;
                }

                $response = Http::withHeaders([
                    'Authorization' => $token,
                ])
                    ->asForm()
                    ->timeout(15)
                    ->post($url, [
                        'target' => trim($pengguna->no_hp),
                        'message' => $notifikasi->pesan,
                    ]);

                $hasil = $response->json();

                if (
                    !$response->successful() ||
                    (
                        is_array($hasil) &&
                        array_key_exists('status', $hasil) &&
                        !in_array($hasil['status'], [true, 1, 'true', '1'], true)
                    )
                ) {
                    $detailError = is_array($hasil)
                        ? json_encode(
                            $hasil,
                            JSON_UNESCAPED_UNICODE
                        )
                        : $response->body();

                    $this->tandaiGagal(
                        $notifikasi->id,
                        'Fonnte gagal: ' . ($detailError ?: 'Respons tidak valid.')
                    );

                    continue;
                }

                DB::table('tb_notifikasi')
                    ->where('id', $notifikasi->id)
                    ->where('status', 'processing')
                    ->update([
                        'status' => 'sent',
                        'terkirim_pada' => now(),
                        'gagal_pada' => null,
                        'pesan_error' => null,
                        'updated_at' => now(),
                    ]);

                Log::info('Respons pengiriman pengingat dari Fonnte.', [
                    'notifikasi_id' => $notifikasi->id,
                    'pengguna_id' => $notifikasi->pengguna_id,
                    'nomor_tujuan' => $pengguna->no_hp,
                    'http_status' => $response->status(),
                    'respons_fonnte' => $response->json() ?? $response->body(),
                ]);
            } catch (Throwable $e) {
                $this->tandaiGagal(
                    $notifikasi->id,
                    $e->getMessage()
                );

                Log::error('Pengiriman pengingat WhatsApp gagal.', [
                    'notifikasi_id' => $notifikasi->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Mencatat kegagalan pengiriman.
     */
    private function tandaiGagal(
        int $notifikasiId,
        string $pesanError
    ): void {
        DB::table('tb_notifikasi')
            ->where('id', $notifikasiId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'gagal_pada' => now(),
                'pesan_error' => mb_substr($pesanError, 0, 5000),
                'updated_at' => now(),
            ]);
    }
}
