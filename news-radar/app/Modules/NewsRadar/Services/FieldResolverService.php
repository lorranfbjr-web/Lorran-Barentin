<?php

namespace App\Modules\NewsRadar\Services;

use App\Modules\NewsRadar\Models\NewsSource;

class ResolvedFields
{
    public function __construct(
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $authorRaw = null,
        public ?string $bodyHtml = null,
        public ?string $bodyText = null,
        public ?string $heroImageUrl = null,
        public ?string $publishedAtRaw = null,
        public ?string $publishedAtSource = null,
        public array $categories = [],
        public array $fieldSources = [],
        public int $extractionCompleteness = 0,
        public string $contentSource = 'feed_only',
    ) {}
}

class FieldResolverService
{
    /**
     * Merge data from feed/listing (raw_payload) and HTML extraction into final resolved fields.
     * Priority: JSON-LD > article HTML > feed data > listing data > OG tags
     */
    public function resolve(
        array $rawPayload,
        array $htmlExtracted,
        NewsSource $source,
    ): ResolvedFields {
        $resolved = new ResolvedFields();

        // Title: JSON-LD headline > article H1 > feed title > listing title > og:title
        $resolved->title = $htmlExtracted['title'] ?? $rawPayload['title'] ?? null;
        $resolved->fieldSources['title'] = $htmlExtracted['field_sources']['title'] ?? 'raw_payload';

        // Subtitle
        $resolved->subtitle = $htmlExtracted['subtitle'] ?? $rawPayload['excerpt'] ?? null;
        $resolved->fieldSources['subtitle'] = $htmlExtracted['field_sources']['subtitle'] ?? (!empty($rawPayload['excerpt']) ? 'raw_payload' : null);

        // Author
        $resolved->authorRaw = $htmlExtracted['author_raw'] ?? $rawPayload['author'] ?? null;
        $resolved->fieldSources['author'] = $htmlExtracted['field_sources']['author'] ?? (!empty($rawPayload['author']) ? 'raw_payload' : null);

        // Body: prefer HTML-extracted body over feed content
        if (!empty($htmlExtracted['body_html'])) {
            $resolved->bodyHtml = $htmlExtracted['body_html'];
            $resolved->bodyText = $htmlExtracted['body_text'] ?? strip_tags($htmlExtracted['body_html']);
            $resolved->fieldSources['body'] = $htmlExtracted['field_sources']['body'] ?? 'html_extraction';
        } elseif (!empty($rawPayload['content_html'])) {
            $resolved->bodyHtml = $rawPayload['content_html'];
            $resolved->bodyText = strip_tags($rawPayload['content_html']);
            $resolved->fieldSources['body'] = 'raw_payload';
        }

        // Image: configurable strategy
        $imgStrategy = $source->crawling_config['image_extraction_strategy'] ?? 'og_first';
        if ($imgStrategy === 'listing_first_then_og_then_body') {
            $resolved->heroImageUrl = $rawPayload['image_url'] ?? $htmlExtracted['hero_image_url'] ?? null;
        } else {
            $resolved->heroImageUrl = $htmlExtracted['hero_image_url'] ?? $rawPayload['image_url'] ?? null;
        }
        $resolved->fieldSources['hero_image_url'] = $htmlExtracted['field_sources']['hero_image_url'] ?? (!empty($rawPayload['image_url']) ? 'raw_payload' : null);

        // Published date
        $resolved->publishedAtRaw = $htmlExtracted['published_at_raw'] ?? $rawPayload['published_at'] ?? null;
        $resolved->publishedAtSource = $htmlExtracted['field_sources']['published_at'] ?? (!empty($rawPayload['published_at']) ? 'rss' : null);

        // Categories
        $resolved->categories = array_unique(array_merge(
            $rawPayload['categories'] ?? [],
            $htmlExtracted['categories'] ?? [],
        ));

        // Content source
        $hasHtml = !empty($htmlExtracted['body_html']);
        $hasFeed = !empty($rawPayload['content_html']);
        if ($hasHtml && $hasFeed) {
            $resolved->contentSource = 'feed_plus_html';
        } elseif ($hasHtml) {
            $resolved->contentSource = 'html_only';
        } else {
            $resolved->contentSource = 'feed_only';
        }

        // Completeness score
        $resolved->extractionCompleteness = $this->calculateCompleteness($resolved);

        return $resolved;
    }

    private function calculateCompleteness(ResolvedFields $fields): int
    {
        $score = 0;

        if ($fields->title) $score += 20;
        if ($fields->bodyText && mb_strlen($fields->bodyText) > 200) $score += 30;
        if ($fields->heroImageUrl) $score += 15;
        if ($fields->authorRaw) $score += 10;
        if ($fields->publishedAtRaw) $score += 15;
        if ($fields->bodyText && mb_strlen($fields->bodyText) > 1000) $score += 10;

        return min(100, $score);
    }
}
