<?php

namespace App\Modules\NewsRadar\Http\Controllers;

use App\Modules\NewsRadar\Models\NewsItem;
use App\Modules\NewsRadar\Models\NewsTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class NewsItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = NewsItem::with(['source:id,name,homepage_url,region', 'aiMetadata'])
            ->whereNull('duplicate_of_id');

        // Keyword search
        if ($q = $request->input('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                  ->orWhere('subtitle', 'like', "%{$q}%")
                  ->orWhere('body_text', 'like', "%{$q}%");
            });
        }

        // Filter by source
        if ($sourceId = $request->input('source_id')) {
            $query->where('news_source_id', $sourceId);
        }

        // Filter by region (via source)
        if ($region = $request->input('region')) {
            $query->whereHas('source', fn($q) => $q->where('region', $region));
        }

        // Filter by theme (via ai_metadata)
        if ($themeId = $request->input('theme_id')) {
            $query->whereHas('aiMetadata', fn($q) => $q->where('news_theme_id', $themeId));
        }

        // Filter by period
        if ($period = $request->input('period')) {
            $hours = match ($period) {
                '6h' => 6,
                '12h' => 12,
                '24h' => 24,
                '48h' => 48,
                '7d' => 168,
                default => null,
            };
            if ($hours) {
                $query->where('created_at', '>=', now()->subHours($hours));
            }
        }

        // Filter by urgency
        if ($urgency = $request->input('urgency')) {
            $query->whereHas('aiMetadata', fn($q) => $q->where('urgency', $urgency));
        }

        // Filter by enrichment status
        if ($enrichment = $request->input('enrichment_status')) {
            $query->where('enrichment_status', $enrichment);
        }

        // Sort
        $sortBy = $request->input('sort', 'recent');
        $query = match ($sortBy) {
            'relevance' => $query->leftJoin('news_item_ai_metadata', 'news_items.id', '=', 'news_item_ai_metadata.news_item_id')
                ->orderByDesc('news_item_ai_metadata.relevance_score')
                ->select('news_items.*'),
            default => $query->orderByDesc('news_items.published_at_utc')
                ->orderByDesc('news_items.created_at'),
        };

        $perPage = min((int) $request->input('limit', 30), 100);
        $items = $query->paginate($perPage);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $item = NewsItem::with(['source', 'aiMetadata', 'media'])->findOrFail($id);

        return response()->json(['data' => $item]);
    }

    public function trending(Request $request): JsonResponse
    {
        $hours = (int) $request->input('hours', 24);

        $items = NewsItem::with(['source:id,name,homepage_url,region', 'aiMetadata'])
            ->whereNull('duplicate_of_id')
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereHas('aiMetadata', fn($q) => $q->where('relevance_score', '>=', 0.7))
            ->leftJoin('news_item_ai_metadata', 'news_items.id', '=', 'news_item_ai_metadata.news_item_id')
            ->orderByDesc('news_item_ai_metadata.relevance_score')
            ->select('news_items.*')
            ->limit(20)
            ->get();

        return response()->json(['data' => $items]);
    }

    public function themes(): JsonResponse
    {
        $themes = NewsTheme::withCount('aiMetadata as items_count')
            ->orderBy('label')
            ->get();

        return response()->json(['data' => $themes]);
    }
}
