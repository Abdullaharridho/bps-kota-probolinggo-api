
<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KegiatanController;
use App\Http\Controllers\Api\KegiatanHarianController;
use App\Http\Controllers\Api\KodeRumahController;
use App\Http\Controllers\Api\LaporanKegiatanController;
use App\Http\Controllers\Api\MasterWilayahController;
use App\Http\Controllers\Api\PemesananRuanganController;
use App\Http\Controllers\Api\PenugasanController;
use App\Http\Controllers\Api\RekomendasiPenugasanController;
use App\Http\Controllers\Api\RuanganController;
use App\Http\Controllers\Api\SuperAdminController;
use App\Http\Controllers\Api\TimController;

use Illuminate\Support\Facades\Route;


// ======================================================
// AUTENTIKASI PUBLIK & LOG AKTIVITAS
// ======================================================

Route::post('/login', [AuthController::class, 'login']);
Route::post('/biometric/login', [AuthController::class, 'biometricLogin']);

Route::post('/activity-log', [ActivityLogController::class, 'store']);
Route::get('/activity-statistics', [ActivityLogController::class, 'statistics']);

Route::post('/fonnte/send', [TimController::class, 'send']);


// ======================================================
// MASTER WILAYAH
// GET PUBLIK, CRUD KHUSUS SUPER ADMIN
// ======================================================

Route::prefix('master-wilayah')->name('master-wilayah.')->group(function () {

    Route::get('/{level}', [MasterWilayahController::class, 'index'])->whereIn('level', ['kecamatan', 'kelurahan', 'rtrw'])->name('index');
    Route::get('/{level}/{id}', [MasterWilayahController::class, 'show'])->whereIn('level', ['kecamatan', 'kelurahan', 'rtrw'])->whereNumber('id')->name('show');

    Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
        Route::post('/{level}', [MasterWilayahController::class, 'store'])->whereIn('level', ['kecamatan', 'kelurahan', 'rtrw'])->name('store');
        Route::put('/{level}/{id}', [MasterWilayahController::class, 'update'])->whereIn('level', ['kecamatan', 'kelurahan', 'rtrw'])->whereNumber('id')->name('update');
        Route::patch('/{level}/{id}', [MasterWilayahController::class, 'update'])->whereIn('level', ['kecamatan', 'kelurahan', 'rtrw'])->whereNumber('id')->name('patch');
        Route::delete('/{level}/{id}', [MasterWilayahController::class, 'destroy'])->whereIn('level', ['kecamatan', 'kelurahan', 'rtrw'])->whereNumber('id')->name('destroy');
    });
});


// ======================================================
// RUTE WAJIB LOGIN
// ======================================================

Route::middleware('auth:sanctum')->group(function () {


    // ==================================================
    // PROFIL & PENGATURAN AKUN
    // ==================================================

    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'updatePassword']);
    Route::post('/biometric/register', [AuthController::class, 'biometricRegister']);


    // ==================================================
    // KODE RUMAH
    // ==================================================

    Route::middleware('role:super_admin,operator,ketua_tim,pimpinan,pegawai,user')->prefix('kode-rumah')->group(function () {
        Route::get('/', [KodeRumahController::class, 'index']);
        Route::post('/', [KodeRumahController::class, 'store']);
        Route::patch('/{id}/retag', [KodeRumahController::class, 'retag'])->whereNumber('id');
        Route::put('/{id}', [KodeRumahController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [KodeRumahController::class, 'destroy'])->whereNumber('id');
        Route::get('/{id}', [KodeRumahController::class, 'show'])->whereNumber('id');
    });


    // ==================================================
    // LOG KODE RUMAH
    // KHUSUS KETUA TIM & PIMPINAN
    // ==================================================

    Route::middleware('role:ketua_tim,pimpinan')->prefix('kode-rumah/log')->group(function () {
        Route::get('/', [KodeRumahController::class, 'logs']);
        Route::get('/{id}', [KodeRumahController::class, 'showLog'])->whereNumber('id');
    });


    // ==================================================
    // MANAJEMEN USER & STATISTIK
    // KHUSUS SUPER ADMIN
    // ==================================================

    Route::middleware('role:super_admin')->group(function () {
        Route::get('/jabatan', [SuperAdminController::class, 'getJabatan']);

        Route::get('/users', [SuperAdminController::class, 'index']);
        Route::post('/users', [SuperAdminController::class, 'store']);
        Route::get('/users/{user}', [SuperAdminController::class, 'show']);
        Route::put('/users/{user}', [SuperAdminController::class, 'update']);
        Route::delete('/users/{user}', [SuperAdminController::class, 'destroy']);

        Route::get('/activity-statistics/admin', [ActivityLogController::class, 'adminStatistics']);
    });


    // ==================================================
    // KEGIATAN HARIAN PEGAWAI
    // ==================================================

    Route::middleware('role:pegawai,pimpinan')->group(function () {
        Route::get('/kegiatan-harian', [KegiatanHarianController::class, 'index']);
        Route::post('/kegiatan-harian', [KegiatanHarianController::class, 'store']);
        Route::get('/kegiatan-harian/{id}', [KegiatanHarianController::class, 'show'])->whereNumber('id');
        Route::put('/kegiatan-harian/{id}', [KegiatanHarianController::class, 'update'])->whereNumber('id');
        Route::delete('/kegiatan-harian/{id}', [KegiatanHarianController::class, 'destroy'])->whereNumber('id');
    });


    // ==================================================
    // KEGIATAN
    // GET SEMUA ROLE
    // TAMBAH, EDIT, HAPUS: OPERATOR & KETUA TIM
    // ==================================================

    Route::middleware('role:super_admin,operator,ketua_tim,pimpinan,pegawai')->group(function () {
        Route::get('/kegiatan', [KegiatanController::class, 'index']);
        Route::get('/kegiatan/{id}', [KegiatanController::class, 'show'])->whereNumber('id');
    });

    Route::middleware('role:operator,ketua_tim')->group(function () {
        Route::post('/kegiatan', [KegiatanController::class, 'store']);
        Route::put('/kegiatan/{id}', [KegiatanController::class, 'update'])->whereNumber('id');
        Route::delete('/kegiatan/{id}', [KegiatanController::class, 'destroy'])->whereNumber('id');
    });


    // ==================================================
    // PENUGASAN
    // GET SEMUA ROLE TERKAIT
    // TAMBAH, EDIT, HAPUS: KHUSUS KETUA TIM
    // ==================================================

   // ==================================================
    // PENUGASAN
    // GET SEMUA ROLE TERKAIT
    // TAMBAH, EDIT, HAPUS, PEGAWAI TERSEDIA: KHUSUS KETUA TIM
    // ==================================================

    Route::middleware('role:super_admin,pimpinan,pegawai,ketua_tim,operator')->group(function () {
        Route::get('/penugasan', [PenugasanController::class, 'index']);
        Route::get('/penugasan/{id}', [PenugasanController::class, 'show'])->whereNumber('id');
    });

    Route::middleware('role:ketua_tim')->group(function () {
        Route::get('/penugasan/pegawai-tersedia', [PenugasanController::class, 'pegawaiTersedia']);
        Route::post('/penugasan', [PenugasanController::class, 'store']);
        Route::put('/penugasan/{id}', [PenugasanController::class, 'update'])->whereNumber('id');
        Route::delete('/penugasan/{id}', [PenugasanController::class, 'destroy'])->whereNumber('id');
    });


    // ==================================================
    // REKOMENDASI PENUGASAN
    // ==================================================

    Route::middleware('role:super_admin,pimpinan,pegawai,ketua_tim,operator')->group(function () {
        Route::get('/penugasan/{id}/pegawai-pengganti', [RekomendasiPenugasanController::class, 'pegawaiPengganti'])->whereNumber('id');

        Route::get('/rekomendasi', [RekomendasiPenugasanController::class, 'index']);
        Route::post('/rekomendasi', [RekomendasiPenugasanController::class, 'store']);
        Route::put('/rekomendasi/{id}', [RekomendasiPenugasanController::class, 'update'])->whereNumber('id');
        Route::patch('/rekomendasi/{id}/proses', [RekomendasiPenugasanController::class, 'proses'])->whereNumber('id');
        Route::delete('/rekomendasi/{id}', [RekomendasiPenugasanController::class, 'destroy'])->whereNumber('id');
    });


    // ==================================================
    // LAPORAN KEGIATAN
    // GET: SEMUA ROLE TERKAIT
    // TAMBAH, EDIT, HAPUS: PEGAWAI
    // ==================================================

    Route::middleware('role:super_admin,pimpinan,operator,ketua_tim,pegawai')->group(function () {
    Route::get('/laporan', [LaporanKegiatanController::class, 'index']);
    Route::get('/laporan/{id}', [LaporanKegiatanController::class, 'show'])->whereNumber('id');
    Route::get('/laporan-kegiatan/dokumen/{id}/preview', [LaporanKegiatanController::class, 'previewDokumen'])->whereNumber('id');
});

    Route::middleware('role:pegawai')->group(function () {
        Route::post('/laporan', [LaporanKegiatanController::class, 'store']);
        Route::put('/laporan/{id}', [LaporanKegiatanController::class, 'update'])->whereNumber('id');
        Route::post('/laporan/{id}', [LaporanKegiatanController::class, 'update'])->whereNumber('id');
        Route::delete('/laporan/{id}', [LaporanKegiatanController::class, 'destroy'])->whereNumber('id');
        
    });


    // ==================================================
    // MANAJEMEN TIM & ANGGOTA
    // ==================================================

    Route::middleware('role:super_admin,pimpinan,pegawai,ketua_tim,operator')->group(function () {
        Route::get('/tim/pegawai-tersedia', [TimController::class, 'pegawaiTersedia']);

        Route::get('/tim', [TimController::class, 'index']);
        Route::post('/tim', [TimController::class, 'store']);
        Route::get('/tim/{id}', [TimController::class, 'show'])->whereNumber('id');
        Route::put('/tim/{id}', [TimController::class, 'update'])->whereNumber('id');
        Route::delete('/tim/{id}', [TimController::class, 'destroy'])->whereNumber('id');

        Route::post('/tim/{id}/anggota', [TimController::class, 'tambahAnggota'])->whereNumber('id');
        Route::delete('/tim/{id}/anggota/{pegawai_id}', [TimController::class, 'hapusAnggota'])->whereNumber('id')->whereNumber('pegawai_id');
    });
});


// ======================================================
// MASTER DATA RUANGAN & PEMESANAN RUANGAN
// KHUSUS OPERATOR & SUPER ADMIN
// ======================================================

Route::middleware(['auth:sanctum', 'role:operator,super_admin'])->group(function () {

    // MASTER DATA RUANGAN

    Route::get('/ruangan', [RuanganController::class, 'index']);
    Route::get('/ruangan/{id}', [RuanganController::class, 'show'])->whereNumber('id');
    Route::post('/ruangan', [RuanganController::class, 'store']);
    Route::put('/ruangan/{id}', [RuanganController::class, 'update'])->whereNumber('id');
    Route::delete('/ruangan/{id}', [RuanganController::class, 'destroy'])->whereNumber('id');


    // PEMESANAN RUANGAN

    Route::get('/pemesanan-ruangan', [PemesananRuanganController::class, 'index']);
    Route::get('/pemesanan-ruangan/{id}', [PemesananRuanganController::class, 'show'])->whereNumber('id');
    Route::post('/pemesanan-ruangan', [PemesananRuanganController::class, 'store']);
    Route::put('/pemesanan-ruangan/{id}', [PemesananRuanganController::class, 'update'])->whereNumber('id');
    Route::patch('/pemesanan-ruangan/{id}/batal', [PemesananRuanganController::class, 'batal'])->whereNumber('id');
    Route::delete('/pemesanan-ruangan/{id}', [PemesananRuanganController::class, 'destroy'])->whereNumber('id');
});
