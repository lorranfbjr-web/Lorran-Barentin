<?php

namespace App\Modules\NewsRadar\Services;

use SimplePie\SimplePie;

class FeedParserService
{
    public function __construct(
        private HttpFetchService $httpFetch,
    ) {}

    /**
     * Parse an RSS/Atom feed URL and return structured items.
     *
     * @return FeedItemDto[]
     */
    public function parse(string $feedUrl): array
    {
        // Pre-fetch XML to handle encoding/blocking
        $result = $this->httpFetch->fetchXml($feedUrl);
        if (!$result->isSuccess()) {
            throw new \RuntimeException("Failed to fetch feed: HTTP {$result->statusCode}");
        }

        $xml = $this->sanitizeXml($result->body);

        // Detect if we got HTML instead of XML (blocked/redirected)
        if (preg_match('/<html[\s>]/i', substr($xml, 0, 500))) {
            throw new \RuntimeException("Feed returned HTML instead of XML (possible block)");
        }

        $feed = new SimplePie();
        $feed->set_raw_data($xml);
        $feed->enable_cache(false);
        $feed->init();

        if ($feed->error()) {
            throw new \RuntimeException("SimplePie error: " . $feed->error());
        }

        $items = [];
        foreach ($feed->get_items() as $item) {
            $link = $item->get_permalink();
            if (!$link) continue;

            $title = $item->get_title();
            if (!$title) continue;

            // Extract author (dc:creator or atom:author)
            $author = $item->get_author()?->get_name();

            // Extract content (content:encoded or full content)
            $contentHtml = $item->get_content();

            // Extract excerpt/description
            $excerpt = $item->get_description();
            if ($excerpt === $contentHtml) {
                $excerpt = null;
            }

            // Extract image from enclosure or media:content
            $imageUrl = $this->extractImage($item);

            // Extract published date
            $publishedAt = $item->get_date('c'); // ISO 8601

            // Extract categories
            $categories = [];
            foreach ($item->get_categories() ?? [] as $cat) {
                $label = $cat->get_label();
                if ($label) $categories[] = $label;
            }

            $items[] = new FeedItemDto(
                url: $link,
                title: html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8'),
                author: $author,
                contentHtml: $contentHtml,
                excerpt: $excerpt ? html_entity_decode(strip_tags($excerpt), ENT_QUOTES, 'UTF-8') : null,
                imageUrl: $imageUrl,
                publishedAt: $publishedAt,
                guid: $item->get_id(),
                categories: $categories,
            );
        }

        return $items;
    }

    private function extractImage(\SimplePie\Item $item): ?string
    {
        // Try enclosure
        $enclosure = $item->get_enclosure();
        if ($enclosure) {
            $type = $enclosure->get_type();
            if ($type && str_starts_with($type, 'image/')) {
                return $enclosure->get_link();
            }
            // media:content or media:thumbnail
            $thumbnails = $enclosure->get_thumbnails();
            if (!empty($thumbnails)) {
                return $thumbnails[0];
            }
            $link = $enclosure->get_link();
            if ($link && preg_match('/\.(jpg|jpeg|png|webp|gif)/i', $link)) {
                return $link;
            }
        }

        // Try content HTML for first <img>
        $content = $item->get_content();
        if ($content && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    private function sanitizeXml(string $xml): string
    {
        // Remove BOM
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }

        // Remove any garbage before XML declaration or root element
        if (preg_match('/(<\?xml|<rss|<feed|<RDF)/i', $xml, $m, PREG_OFFSET_CAPTURE)) {
            $xml = substr($xml, $m[0][1]);
        }

        // Remove invalid XML characters
        $xml = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xml);

        return $xml;
    }
}
