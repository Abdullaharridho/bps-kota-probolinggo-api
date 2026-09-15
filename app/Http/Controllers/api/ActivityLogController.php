<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    /**
     * Mencatat aktivitas pengguna anonim.
     *
     * Endpoint ini bersifat PUBLIC.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'anonymous_id' => [
                'required',
                'string',
                'max:100',
            ],

            'event' => [
                'required',
                'string',
                'max:50',
                Rule::in([
                    'app_open',
                    'screen_view',
                ]),
            ],

            'screen' => [
                'nullable',
                'string',
                'max:100',
            ],

            'device_model' => [
                'nullable',
                'string',
                'max:255',
            ],

            'android_version' => [
                'nullable',
                'integer',
                'min:21',
                'max:100',
            ],

            'metadata' => [
                'nullable',
                'array',
            ],
        ]);

        $activityLog = ActivityLog::create([
            'anonymous_id' => $validated['anonymous_id'],
            'event' => $validated['event'],
            'screen' => $validated['screen'] ?? null,
            'device_model' => $validated['device_model'] ?? null,
            'android_version' => $validated['android_version'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Aktivitas berhasil dicatat.',
            'data' => [
                'id' => $activityLog->id,
            ],
        ], 201);
    }

    /**
     * Statistik penggunaan aplikasi untuk pengguna PUBLIC.
     *
     * Endpoint ini hanya mengembalikan statistik agregat
     * yang aman ditampilkan kepada pengguna umum.
     */
    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'start_date' => [
                'nullable',
                'date',
            ],

            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
        ]);

        $startDate = $validated['start_date']
            ?? now()->startOfMonth()->toDateString();

        $endDate = $validated['end_date']
            ?? now()->toDateString();

        $query = ActivityLog::query()
            ->whereBetween('created_at', [
                $startDate . ' 00:00:00',
                $endDate . ' 23:59:59',
            ]);

        /*
         * Jumlah anonymous_id unik.
         *
         * Ditampilkan sebagai:
         * "Pengguna yang Mengakses"
         */
        $uniqueUsers = (clone $query)
            ->distinct('anonymous_id')
            ->count('anonymous_id');

        /*
         * Total seluruh aktivitas yang tercatat.
         */
        $totalAccess = (clone $query)
            ->count();

        /*
         * Statistik halaman yang paling banyak diakses.
         *
         * Hanya screen + total akses.
         * Tidak membocorkan anonymous_id.
         */
        $screenStatistics = (clone $query)
            ->whereNotNull('screen')
            ->select(
                'screen',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('screen')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,

            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],

            'summary' => [
                'users_accessing' => $uniqueUsers,
                'total_access' => $totalAccess,
            ],

            'popular_screens' => $screenStatistics,
        ]);
    }

    /**
     * Statistik penggunaan aplikasi untuk SUPER ADMIN.
     *
     * Endpoint ini dapat memberikan informasi statistik
     * yang lebih lengkap dibanding endpoint public.
     */
    public function adminStatistics(Request $request)
    {
        $validated = $request->validate([
            'start_date' => [
                'nullable',
                'date',
            ],

            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
        ]);

        $startDate = $validated['start_date']
            ?? now()->startOfMonth()->toDateString();

        $endDate = $validated['end_date']
            ?? now()->toDateString();

        $query = ActivityLog::query()
            ->whereBetween('created_at', [
                $startDate . ' 00:00:00',
                $endDate . ' 23:59:59',
            ]);

        /*
         * Jumlah anonymous_id unik.
         */
        $uniqueUsers = (clone $query)
            ->distinct('anonymous_id')
            ->count('anonymous_id');

        /*
         * Total seluruh aktivitas.
         */
        $totalAccess = (clone $query)
            ->count();

        /*
         * Statistik event.
         */
        $eventStatistics = (clone $query)
            ->select(
                'event',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('event')
            ->orderByDesc('total')
            ->get();

        /*
         * Statistik screen.
         */
        $screenStatistics = (clone $query)
            ->whereNotNull('screen')
            ->select(
                'screen',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('screen')
            ->orderByDesc('total')
            ->get();

        /*
         * Statistik perangkat.
         *
         * Yang dihitung adalah anonymous_id unik
         * pada setiap tipe perangkat.
         */
        $deviceStatistics = (clone $query)
            ->whereNotNull('device_model')
            ->select(
                'device_model',
                DB::raw('COUNT(DISTINCT anonymous_id) as total_users')
            )
            ->groupBy('device_model')
            ->orderByDesc('total_users')
            ->get();

        /*
         * Statistik versi Android.
         */
        $androidStatistics = (clone $query)
            ->whereNotNull('android_version')
            ->select(
                'android_version',
                DB::raw('COUNT(DISTINCT anonymous_id) as total_users')
            )
            ->groupBy('android_version')
            ->orderByDesc('total_users')
            ->get();

        return response()->json([
            'success' => true,

            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],

            'summary' => [
                'users_accessing' => $uniqueUsers,
                'total_access' => $totalAccess,
            ],

            'events' => $eventStatistics,
            'screens' => $screenStatistics,
            'devices' => $deviceStatistics,
            'android_versions' => $androidStatistics,
        ]);
    }
}