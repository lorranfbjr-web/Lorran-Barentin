<?php

namespace App\Modules\NewsRadar\Jobs;

use App\Modules\NewsRadar\Enums\ExtractionStatus;
use App\Modules\NewsRadar\Enums\FetchDetailMode;
use App\Modules\NewsRadar\Enums\RawItemStatus;
use App\Modules\NewsRadar\Models\NewsItem;
use App\Modules\NewsRadar\Models\NewsRawItem;
use App\Modules\NewsRadar\Services\ArticleExtractorService;
use App\Modules\NewsRadar\Services\DateParserService;
use App\Modules\NewsRadar\Services\FieldResolverService;
use App\Modules\NewsRadar\Services\HttpFetchService;
use App\Modules\NewsRadar\Services\UrlNormalizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessNewsItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        private int $rawItemId,
    ) {
        $this->queue = 'news-radar';
    }

    public function handle(
        HttpFetchService $httpFetch,
        ArticleExtractorService $articleExtractor,
        FieldResolverService $fieldResolver,
        DateParserService $dateParser,
        UrlNormalizerService $urlNormalizer,
    ): void {
        $rawItem = NewsRawItem::with('source')->find($this->rawItemId);
        if (!$rawItem || $rawItem->processing_status !== RawItemStatus::Pending) return;

        $rawItem->update(['processing_status' => RawItemStatus::Processing]);

        $source = $rawItem->source;
        $rawPayload = $rawItem->raw_payload;
        $fetchMode = $source->fetch_detail_mode;

        try {
            $htmlExtracted = [];

            $shouldFetch = match ($fetchMode) {
                FetchDetailMode::Always => true,
                FetchDetailMode::Never => false,
                FetchDetailMode::WhenIncomplete => $this->isIncomplete($rawPayload),
            };

            if ($shouldFetch) {
                $rawItem->increment('fetch_attempts');

                $result = $httpFetch->fetch($rawItem->normalized_url);
                if ($result->isSuccess()) {
                    $config = $source->crawling_config ?? [];
                    $htmlExtracted = $articleExtractor->extract(
                        $result->body,
                        $rawItem->normalized_url,
                        $config
                    );
                }
            }

            // Resolve fields by merging raw payload + HTML extraction
            $resolved = $fieldResolver->resolve($rawPayload, $htmlExtracted, $source);

            if (!$resolved->title) {
                $rawItem->update(['processing_status' => RawItemStatus::Failed]);
                return;
            }

            // Parse date
            $publishedAtParsed = null;
            $publishedAtUtc = null;
            $timezone = $source->timezone_default ?? 'America/Sao_Paulo';

            if ($resolved->publishedAtRaw) {
                $customFormats = $source->date_formats ?? [];
                $publishedAtParsed = $dateParser->parse($resolved->publishedAtRaw, $timezone, $customFormats);
                if ($publishedAtParsed) {
                    $publishedAtUtc = $publishedAtParsed->copy()->utc();
                }
            }

            $urlHash = $urlNormalizer->hash($rawItem->normalized_url);

            // Check for duplicate
            $existing = NewsItem::where('url_hash', $urlHash)->first();
            if ($existing) {
                $rawItem->update(['processing_status' => RawItemStatus::Promoted]);
                return;
            }

            $newsItem = NewsItem::create([
                'news_source_id' => $source->id,
                'news_raw_item_id' => $rawItem->id,
                'title' => $resolved->title,
                'subtitle' => $resolved->subtitle,
                'author_raw' => $resolved->authorRaw,
                'body_html' => $resolved->bodyHtml,
                'body_text' => $resolved->bodyText,
                'hero_image_url' => $resolved->heroImageUrl,
                'url' => $rawItem->normalized_url,
                'url_hash' => $urlHash,
                'published_at_raw' => $resolved->publishedAtRaw,
                'published_at_parsed' => $publishedAtParsed,
                'published_at_utc' => $publishedAtUtc,
                'published_at_timezone' => $timezone,
                'published_at_source' => $resolved->publishedAtSource,
                'extraction_completeness' => $resolved->extractionCompleteness,
                'content_source' => $resolved->contentSource,
                'extraction_status' => ExtractionStatus::Extracted,
                'field_sources' => $resolved->fieldSources,
                'categories' => $resolved->categories ?: null,
            ]);

            $rawItem->update(['processing_status' => RawItemStatus::Promoted]);

            // Dispatch AI classification
            ClassifyNewsItemJob::dispatch($newsItem->id)->onQueue('news-radar-ai');

            Log::debug("[NewsRadar] Processed: {$resolved->title} (score: {$resolved->extractionCompleteness})");

        } catch (\Throwable $e) {
            $rawItem->update(['processing_status' => RawItemStatus::Failed]);
            Log::error("[NewsRadar] Process failed for {$rawItem->normalized_url}: {$e->getMessage()}");
        }
    }

    private function isIncomplete(array $payload): bool
    {
        $hasBody = !empty($payload['content_html']) && mb_strlen(strip_tags($payload['content_html'])) > 600;
        $hasImage = !empty($payload['image_url']);
        $hasAuthor = !empty($payload['author']);

        return !$hasBody || !$hasImage || !$hasAuthor;
    }
}
