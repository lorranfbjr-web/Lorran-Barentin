<?php

namespace App\Modules\NewsRadar\Services;

use Symfony\Component\DomCrawler\Crawler;

class ListingItem
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $excerpt = null,
    ) {}

    public function toRawPayload(): array
    {
        return array_filter([
            'title' => $this->title,
            'image_url' => $this->imageUrl,
            'excerpt' => $this->excerpt,
        ], fn ($v) => $v !== null);
    }
}

class ListingDiscoveryService
{
    public function __construct(
        private HttpFetchService $httpFetch,
        private UrlNormalizerService $urlNormalizer,
    ) {}

    /**
     * Discover news articles from an HTML listing page.
     *
     * @return ListingItem[]
     */
    public function discover(string $pageUrl, array $config): array
    {
        $result = $this->httpFetch->fetch($pageUrl);
        if (!$result->isSuccess()) {
            throw new \RuntimeException("Failed to fetch listing: HTTP {$result->statusCode}");
        }

        $crawler = new Crawler($result->body, $pageUrl);
        $items = [];

        $containerSelectors = $config['listing_container_selectors'] ?? ['main', 'body'];
        $itemSelectors = $config['listing_item_selectors'] ?? ['article', '.post'];
        $linkSelectors = $config['listing_link_selectors'] ?? ['a'];
        $titleSelectors = $config['listing_title_selectors'] ?? ['h2', 'h3', 'h1'];
        $imageSelectors = $config['listing_image_selectors'] ?? ['img'];
        $excerptSelectors = $config['listing_excerpt_selectors'] ?? ['.excerpt', '.resumo', 'p'];
        $articlePatterns = $config['article_url_patterns'] ?? [];
        $ignorePatterns = $config['ignore_url_patterns'] ?? [];

        // Find the container
        $container = null;
        foreach ($containerSelectors as $sel) {
            try {
                $found = $crawler->filter($sel);
                if ($found->count() > 0) {
                    $container = $found->first();
                    break;
                }
            } catch (\Exception) {
                continue;
            }
        }

        if (!$container) {
            $container = $crawler;
        }

        // Find items within container
        foreach ($itemSelectors as $itemSel) {
            try {
                $container->filter($itemSel)->each(function (Crawler $node) use (
                    $linkSelectors, $titleSelectors, $imageSelectors, $excerptSelectors,
                    $articlePatterns, $ignorePatterns, $pageUrl, &$items
                ) {
                    $item = $this->extractItem(
                        $node, $linkSelectors, $titleSelectors, $imageSelectors,
                        $excerptSelectors, $articlePatterns, $ignorePatterns, $pageUrl
                    );
                    if ($item) {
                        $items[] = $item;
                    }
                });

                if (!empty($items)) break; // Stop at first successful selector
            } catch (\Exception) {
                continue;
            }
        }

        return $items;
    }

    /**
     * Discover from multiple listing URLs (with optional pagination).
     *
     * @return ListingItem[]
     */
    public function discoverFromSource(array $config): array
    {
        $listingUrls = $config['listing_urls'] ?? [];
        $allItems = [];

        foreach ($listingUrls as $url) {
            $items = $this->discover($url, $config);
            $allItems = array_merge($allItems, $items);
        }

        // Deduplicate by URL
        $seen = [];
        return array_filter($allItems, function (ListingItem $item) use (&$seen) {
            $normalized = $this->urlNormalizer->normalize($item->url);
            if (isset($seen[$normalized])) return false;
            $seen[$normalized] = true;
            return true;
        });
    }

    private function extractItem(
        Crawler $node,
        array $linkSelectors,
        array $titleSelectors,
        array $imageSelectors,
        array $excerptSelectors,
        array $articlePatterns,
        array $ignorePatterns,
        string $baseUrl,
    ): ?ListingItem {
        // Extract link
        $url = null;
        foreach ($linkSelectors as $sel) {
            try {
                $link = $node->filter($sel)->first();
                if ($link->count() > 0) {
                    $href = $link->attr('href');
                    if ($href) {
                        $url = $this->urlNormalizer->resolveRelative($href, $baseUrl);
                        break;
                    }
                }
            } catch (\Exception) {
                continue;
            }
        }
        if (!$url || str_starts_with($url, 'javascript:') || $url === '#') return null;

        // Check URL patterns
        if (!empty($articlePatterns)) {
            $matches = false;
            foreach ($articlePatterns as $pattern) {
                if (str_contains($url, $pattern)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) return null;
        }

        foreach ($ignorePatterns as $pattern) {
            if (str_contains($url, $pattern)) return null;
        }

        // Extract title
        $title = null;
        foreach ($titleSelectors as $sel) {
            try {
                $titleNode = $node->filter($sel)->first();
                if ($titleNode->count() > 0) {
                    $title = trim($titleNode->text());
                    break;
                }
            } catch (\Exception) {
                continue;
            }
        }

        // Extract image
        $imageUrl = null;
        foreach ($imageSelectors as $sel) {
            try {
                $img = $node->filter($sel)->first();
                if ($img->count() > 0) {
                    $src = $img->attr('data-src') ?? $img->attr('data-lazy-src') ?? $img->attr('src');
                    if ($src) {
                        $imageUrl = $this->urlNormalizer->resolveRelative($src, $baseUrl);
                        break;
                    }
                }
            } catch (\Exception) {
                continue;
            }
        }

        // Extract excerpt
        $excerpt = null;
        foreach ($excerptSelectors as $sel) {
            try {
                $excerptNode = $node->filter($sel)->first();
                if ($excerptNode->count() > 0) {
                    $excerpt = trim($excerptNode->text());
                    if (mb_strlen($excerpt) > 10) break;
                    $excerpt = null;
                }
            } catch (\Exception) {
                continue;
            }
        }

        return new ListingItem(
            url: $url,
            title: $title,
            imageUrl: $imageUrl,
            excerpt: $excerpt,
        );
    }
}
