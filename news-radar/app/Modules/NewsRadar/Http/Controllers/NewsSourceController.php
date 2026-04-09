<?php

namespace App\Modules\NewsRadar\Http\Controllers;

use App\Modules\NewsRadar\Jobs\FetchNewsSourceJob;
use App\Modules\NewsRadar\Models\NewsSource;
use App\Modules\NewsRadar\Models\NewsSourceRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class NewsSourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NewsSource::query();

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($region = $request->input('region')) {
            $query->where('region', $region);
        }

        $sources = $query->withCount('items')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $sources]);
    }

    public function show(int $id): JsonResponse
    {
        $source = NewsSource::withCount(['items', 'rawItems', 'runs'])
            ->findOrFail($id);

        return response()->json(['data' => $source]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'homepage_url' => 'required|url|max:500',
            'source_type' => 'sometimes|string',
            'discovery_mode' => 'sometimes|string',
            'fetch_detail_mode' => 'sometimes|string',
            'region' => 'sometimes|string|max:100',
            'crawling_config' => 'sometimes|array',
            'throttle_config' => 'sometimes|array',
        ]);

        $source = NewsSource::create(array_merge($validated, [
            'active' => true,
            'consecutive_failures' => 0,
        ]));

        return response()->json(['data' => $source], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $source = NewsSource::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'homepage_url' => 'sometimes|url|max:500',
            'source_type' => 'sometimes|string',
            'discovery_mode' => 'sometimes|string',
            'fetch_detail_mode' => 'sometimes|string',
            'region' => 'sometimes|string|max:100',
            'crawling_config' => 'sometimes|array',
            'throttle_config' => 'sometimes|array',
            'active' => 'sometimes|boolean',
        ]);

        $source->update($validated);

        return response()->json(['data' => $source->fresh()]);
    }

    public function toggle(int $id): JsonResponse
    {
        $source = NewsSource::findOrFail($id);
        $source->update([
            'active' => !$source->active,
            'consecutive_failures' => $source->active ? $source->consecutive_failures : 0,
        ]);

        return response()->json([
            'data' => $source->fresh(),
            'message' => $source->fresh()->active ? 'Source activated' : 'Source deactivated',
        ]);
    }

    public function fetchNow(int $id): JsonResponse
    {
        $source = NewsSource::findOrFail($id);

        if ($source->isLocked()) {
            return response()->json(['message' => 'Source is currently locked/running'], 409);
        }

        FetchNewsSourceJob::dispatch($source->id);

        return response()->json(['message' => 'Fetch job dispatched']);
    }

    public function runs(Request $request, int $id): JsonResponse
    {
        $source = NewsSource::findOrFail($id);

        $runs = $source->runs()
            ->orderByDesc('created_at')
            ->limit((int) $request->input('limit', 20))
            ->get();

        return response()->json(['data' => $runs]);
    }

    public function collectAll(): JsonResponse
    {
        $sources = NewsSource::where('active', true)->get();
        $dispatched = 0;

        foreach ($sources as $source) {
            if (!$source->isLocked()) {
                FetchNewsSourceJob::dispatch($source->id);
                $dispatched++;
            }
        }

        return response()->json([
            'message' => "Dispatched {$dispatched} source(s) for fetching",
            'dispatched' => $dispatched,
        ]);
    }

    public function regions(): JsonResponse
    {
        $regions = NewsSource::select('region')
            ->whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region');

        return response()->json(['data' => $regions]);
    }
}
