<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateStatusPemesananRuangan extends Command
{
    protected $signature = 'ruangan:update-status';

    protected $description = 'Memperbarui status pemesanan ruangan berdasarkan tanggal dan waktu';

    public function handle(): int
    {
        $sekarang = now();

        // dipesan -> digunakan
        DB::table('tb_pemesanan_ruangan')
            ->where('status', 'dipesan')
            ->whereDate('tanggal', $sekarang->toDateString())
            ->where('waktu_mulai', '<=', $sekarang->format('H:i:s'))
            ->where('waktu_selesai', '>', $sekarang->format('H:i:s'))
            ->update([
                'status' => 'digunakan',
                'updated_at' => $sekarang,
            ]);

        // dipesan/digunakan -> selesai
        DB::table('tb_pemesanan_ruangan')
            ->whereIn('status', ['dipesan', 'digunakan'])
            ->where(function ($query) use ($sekarang) {
                $query->whereDate('tanggal', '<', $sekarang->toDateString())
                    ->orWhere(function ($q) use ($sekarang) {
                        $q->whereDate('tanggal', $sekarang->toDateString())
                            ->where('waktu_selesai', '<=', $sekarang->format('H:i:s'));
                    });
            })
            ->update([
                'status' => 'selesai',
                'updated_at' => $sekarang,
            ]);

        $this->info('Status pemesanan ruangan berhasil diperbarui.');

        return self::SUCCESS;
    }
}