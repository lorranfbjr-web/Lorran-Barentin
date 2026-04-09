<?php

namespace App\Modules\NewsRadar\Jobs;

use App\Modules\NewsRadar\Enums\DiscoveryMode;
use App\Modules\NewsRadar\Enums\RawItemStatus;
use App\Modules\NewsRadar\Enums\SourceRunStatus;
use App\Modules\NewsRadar\Models\NewsRawItem;
use App\Modules\NewsRadar\Models\NewsSource;
use App\Modules\NewsRadar\Models\NewsSourceRun;
use App\Modules\NewsRadar\Services\FeedItemDto;
use App\Modules\NewsRadar\Services\FeedParserService;
use App\Modules\NewsRadar\Services\ListingDiscoveryService;
use App\Modules\NewsRadar\Services\ListingItem;
use App\Modules\NewsRadar\Services\UrlNormalizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchNewsSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        private int $newsSourceId,
    ) {
        $this->queue = 'news-radar';
    }

    public function handle(
        FeedParserService $feedParser,
        ListingDiscoveryService $listingDiscovery,
        UrlNormalizerService $urlNormalizer,
    ): void {
        $source = NewsSource::find($this->newsSourceId);
        if (!$source || !$source->active) return;

        if (!$source->acquireLock()) {
            Log::debug("[NewsRadar] Source {$source->name} is locked, skipping");
            return;
        }

        $run = NewsSourceRun::create([
            'news_source_id' => $source->id,
            'status' => SourceRunStatus::Running,
            'started_at' => now(),
        ]);

        $startTime = microtime(true);

        try {
            $rawItems = $this->discover($source, $feedParser, $listingDiscovery);
            $discoveryMode = $source->discovery_mode->value;

            $itemsNew = 0;
            $itemsUpdated = 0;

            foreach ($rawItems as $item) {
                $url = $item['url'];
                $normalizedUrl = $urlNormalizer->normalize($url);
                $urlHash = $urlNormalizer->hash($url);

                $existing = NewsRawItem::where('news_source_id', $source->id)
                    ->where('url_hash', $urlHash)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'last_seen_at' => now(),
                        'seen_count' => $existing->seen_count + 1,
                    ]);
                    $itemsUpdated++;
                } else {
                    $rawItem = NewsRawItem::create([
                        'news_source_id' => $source->id,
                        'news_source_run_id' => $run->id,
                        'raw_url' => $url,
                        'normalized_url' => $normalizedUrl,
                        'url_hash' => $urlHash,
                        'guid' => $item['guid'] ?? null,
                        'raw_payload' => $item['payload'],
                        'processing_status' => RawItemStatus::Pending,
                        'last_seen_at' => now(),
                    ]);
                    $itemsNew++;

                    // Dispatch processing job
                    ProcessNewsItemJob::dispatch($rawItem->id)->onQueue('news-radar');
                }
            }

            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            $run->update([
                'status' => SourceRunStatus::Success,
                'discovery_mode_used' => $discoveryMode,
                'items_found' => count($rawItems),
                'items_new' => $itemsNew,
                'items_updated' => $itemsUpdated,
                'duration_ms' => $durationMs,
                'finished_at' => now(),
            ]);

            $source->recordSuccess($itemsNew);

            Log::info("[NewsRadar] {$source->name}: {$itemsNew} new, {$itemsUpdated} updated ({$durationMs}ms)");

        } catch (\Throwable $e) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            $run->update([
                'status' => SourceRunStatus::Failed,
                'error_message' => $e->getMessage(),
                'duration_ms' => $durationMs,
                'finished_at' => now(),
            ]);

            $source->recordFailure();

            Log::error("[NewsRadar] {$source->name} failed: {$e->getMessage()}");
        }
    }

    private function discover(
        NewsSource $source,
        FeedParserService $feedParser,
        ListingDiscoveryService $listingDiscovery,
    ): array {
        $mode = $source->discovery_mode;
        $config = $source->crawling_config ?? [];

        return match ($mode) {
            DiscoveryMode::Feed => $this->discoverViaFeed($source, $feedParser),
            DiscoveryMode::HtmlListing => $this->discoverViaListing($source, $listingDiscovery),
            DiscoveryMode::Sitemap => $this->discoverViaFeed($source, $feedParser), // fallback to feed for now
            DiscoveryMode::Auto => $this->discoverAuto($source, $feedParser, $listingDiscovery),
        };
    }

    private function discoverAuto(
        NewsSource $source,
        FeedParserService $feedParser,
        ListingDiscoveryService $listingDiscovery,
    ): array {
        // Try feed first
        try {
            $items = $this->discoverViaFeed($source, $feedParser);
            if (!empty($items)) return $items;
        } catch (\Exception) {}

        // Try listing
        try {
            return $this->discoverViaListing($source, $listingDiscovery);
        } catch (\Exception) {}

        return [];
    }

    private function discoverViaFeed(NewsSource $source, FeedParserService $feedParser): array
    {
        $config = $source->crawling_config ?? [];
        $feedUrl = $config['feed_url'] ?? $source->homepage_url . '/feed/';

        $feedItems = $feedParser->parse($feedUrl);
        $articlePatterns = $config['article_url_patterns'] ?? [];
        $ignorePatterns = $config['ignore_url_patterns'] ?? [];

        return array_filter(array_map(function (FeedItemDto $item) use ($articlePatterns, $ignorePatterns) {
            // Filter by URL patterns
            if (!empty($articlePatterns)) {
                $matches = false;
                foreach ($articlePatterns as $pattern) {
                    if (str_contains($item->url, $pattern)) { $matches = true; break; }
                }
                if (!$matches) return null;
            }
            foreach ($ignorePatterns as $pattern) {
                if (str_contains($item->url, $pattern)) return null;
            }

            return [
                'url' => $item->url,
                'guid' => $item->guid,
                'payload' => $item->toRawPayload(),
            ];
        }, $feedItems));
    }

    private function discoverViaListing(NewsSource $source, ListingDiscoveryService $listingDiscovery): array
    {
        $config = $source->crawling_config ?? [];

        if (empty($config['listing_urls'])) {
            $config['listing_urls'] = [$source->homepage_url];
        }

        $listingItems = $listingDiscovery->discoverFromSource($config);

        return array_map(function (ListingItem $item) {
            return [
                'url' => $item->url,
                'guid' => null,
                'payload' => $item->toRawPayload(),
            ];
        }, $listingItems);
    }
}
