<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SuperAdminController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/biometric/login', [AuthController::class, 'biometricLogin']);
Route::post('/activity-log', [ActivityLogController::class, 'store']);
Route::get('/activity-statistics', [
    ActivityLogController::class,
    'statistics',
]);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', [AuthController::class, 'user']);
    

    Route::post('/logout', [AuthController::class, 'logout']);
     Route::put('/profile', [AuthController::class, 'updateProfile']);

    Route::put('/profile/password', [AuthController::class, 'updatePassword']);
     Route::post('/biometric/register', [AuthController::class, 'biometricRegister']);

    Route::middleware('super_admin')->group(function () {

        Route::get('/users', [SuperAdminController::class, 'index']);

        Route::post('/users', [SuperAdminController::class, 'store']);

        Route::get('/users/{user}', [SuperAdminController::class, 'show']);

        Route::put('/users/{user}', [SuperAdminController::class, 'update']);

        Route::delete('/users/{user}', [SuperAdminController::class, 'destroy']);

         Route::get('/activity-statistics/admin', [
            ActivityLogController::class,
            'adminStatistics',
        ]);
    });
});