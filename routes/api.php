<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SuperAdminController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SuratMasukController;
use App\Http\Controllers\Api\PenugasanController;
use App\Http\Controllers\Api\TimController;
use App\Http\Controllers\Api\RekomendasiPenugasanController;
use App\Http\Controllers\Api\LaporanKegiatanController;

// Autentikasi Publik & Log Aktivitas
Route::post('/login', [AuthController::class, 'login']);
Route::post('/biometric/login', [AuthController::class, 'biometricLogin']);
Route::post('/activity-log', [ActivityLogController::class, 'store']);
Route::get('/activity-statistics', [ActivityLogController::class, 'statistics']);

// Rute Wajib Login
Route::middleware('auth:sanctum')->group(function () {

    // Profil & Pengaturan Akun
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'updatePassword']);
    Route::post('/biometric/register', [AuthController::class, 'biometricRegister']);

    // Manajemen User & Statistik (Khusus Super Admin)
    Route::middleware('role:super_admin')->group(function () {
        Route::get('/users', [SuperAdminController::class, 'index']);
        Route::post('/users', [SuperAdminController::class, 'store']);
        Route::get('/users/{user}', [SuperAdminController::class, 'show']);
        Route::put('/users/{user}', [SuperAdminController::class, 'update']);
        Route::delete('/users/{user}', [SuperAdminController::class, 'destroy']);
        Route::get('/activity-statistics/admin', [ActivityLogController::class, 'adminStatistics']);
    });

    // Modul Surat Masuk (Akses: Super Admin, Operator)
    Route::middleware('role:super_admin,operator')->group(function () {
        Route::get('/surat-masuk', [SuratMasukController::class, 'index']);
        Route::post('/surat-masuk', [SuratMasukController::class, 'store']);
        Route::get('/surat-masuk/{id}', [SuratMasukController::class, 'show']);
        Route::post('/surat-masuk/{id}', [SuratMasukController::class, 'update']);
        Route::patch('/surat-masuk/{id}/status', [SuratMasukController::class, 'updateStatus']);
        Route::delete('/surat-masuk/{id}', [SuratMasukController::class, 'destroy']);
    });

    // Modul Penugasan, Kolaborasi, dan Tim (Akses: Super Admin, Pimpinan, User)
    Route::middleware('role:super_admin,pimpinan,user')->group(function () {

        // Penugasan
        Route::get('/penugasan', [PenugasanController::class, 'index']);
        Route::post('/penugasan', [PenugasanController::class, 'store']);
        Route::get('/penugasan/{id}', [PenugasanController::class, 'show']);
        Route::put('/penugasan/{id}', [PenugasanController::class, 'update']);
        Route::delete('/penugasan/{id}', [PenugasanController::class, 'destroy']);
        Route::patch('/penugasan/{id}/respon', [PenugasanController::class, 'responPenugasan']);

        // Rekomendasi Penugasan (Swap Request)
        Route::get('/rekomendasi', [RekomendasiPenugasanController::class, 'index']);
        Route::post('/rekomendasi', [RekomendasiPenugasanController::class, 'store']);
        Route::put('/rekomendasi/{id}', [RekomendasiPenugasanController::class, 'update']);
        Route::patch('/rekomendasi/{id}/proses', [RekomendasiPenugasanController::class, 'proses']);
        Route::delete('/rekomendasi/{id}', [RekomendasiPenugasanController::class, 'destroy']);

        // Laporan Kegiatan
        Route::get('/laporan', [LaporanKegiatanController::class, 'index']);
        Route::post('/laporan', [LaporanKegiatanController::class, 'store']);
        Route::get('/laporan/{id}', [LaporanKegiatanController::class, 'show']);
        Route::post('/laporan/{id}', [LaporanKegiatanController::class, 'update']);
        Route::delete('/laporan/{id}', [LaporanKegiatanController::class, 'destroy']);

        // Manajemen Tim & Anggota
        Route::get('/tim', [TimController::class, 'index']);
        Route::post('/tim', [TimController::class, 'store']);
        Route::get('/tim/{id}', [TimController::class, 'show']);
        Route::put('/tim/{id}', [TimController::class, 'update']);
        Route::delete('/tim/{id}', [TimController::class, 'destroy']);
        Route::post('/tim/{id}/anggota', [TimController::class, 'tambahAnggota']);
        Route::delete('/tim/{id}/anggota/{pegawai_id}', [TimController::class, 'hapusAnggota']);
    });
});
