<?php

namespace App\Modules\NewsRadar\Http\Controllers;

use App\Modules\NewsRadar\Models\NewsItem;
use App\Modules\NewsRadar\Models\NewsSource;
use App\Modules\NewsRadar\Models\NewsSourceRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $now = now();

        return response()->json([
            'data' => [
                'sources' => [
                    'total' => NewsSource::count(),
                    'active' => NewsSource::where('active', true)->count(),
                    'failing' => NewsSource::where('consecutive_failures', '>', 0)
                        ->where('active', true)->count(),
                ],
                'items' => [
                    'total' => NewsItem::count(),
                    'last_24h' => NewsItem::where('created_at', '>=', $now->copy()->subDay())->count(),
                    'last_1h' => NewsItem::where('created_at', '>=', $now->copy()->subHour())->count(),
                ],
                'runs' => [
                    'last_24h' => NewsSourceRun::where('created_at', '>=', $now->copy()->subDay())->count(),
                    'failed_last_24h' => NewsSourceRun::where('created_at', '>=', $now->copy()->subDay())
                        ->where('status', 'failed')->count(),
                ],
                'last_collection' => NewsSourceRun::orderByDesc('finished_at')
                    ->value('finished_at'),
            ],
        ]);
    }
}
