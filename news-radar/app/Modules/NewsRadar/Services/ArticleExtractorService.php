<?php

namespace App\Modules\NewsRadar\Services;

use Symfony\Component\DomCrawler\Crawler;

class ArticleExtractorService
{
    public function __construct(
        private BoilerplateCleanerService $boilerplateCleaner,
        private UrlNormalizerService $urlNormalizer,
    ) {}

    /**
     * Extract article data from HTML using a 4-layer strategy:
     * A: JSON-LD → B: Open Graph → C: HTML selectors → D: Boilerplate cleaning
     */
    public function extract(string $html, string $baseUrl, array $config = []): array
    {
        $crawler = new Crawler($html, $baseUrl);
        $extracted = [];
        $sources = [];

        // Layer A: JSON-LD (schema.org/NewsArticle)
        $jsonLd = $this->extractJsonLd($crawler);
        if ($jsonLd) {
            if (!empty($jsonLd['headline'])) {
                $extracted['title'] = $jsonLd['headline'];
                $sources['title'] = 'jsonld';
            }
            if (!empty($jsonLd['description'])) {
                $extracted['subtitle'] = $jsonLd['description'];
                $sources['subtitle'] = 'jsonld';
            }
            if (!empty($jsonLd['image'])) {
                $img = is_array($jsonLd['image']) ? ($jsonLd['image']['url'] ?? $jsonLd['image'][0] ?? null) : $jsonLd['image'];
                if ($img) {
                    $extracted['hero_image_url'] = $img;
                    $sources['hero_image_url'] = 'jsonld';
                }
            }
            if (!empty($jsonLd['datePublished'])) {
                $extracted['published_at_raw'] = $jsonLd['datePublished'];
                $sources['published_at'] = 'jsonld';
            }
            if (!empty($jsonLd['author'])) {
                $author = is_array($jsonLd['author']) ? ($jsonLd['author']['name'] ?? $jsonLd['author'][0]['name'] ?? null) : $jsonLd['author'];
                if ($author) {
                    $extracted['author_raw'] = $author;
                    $sources['author'] = 'jsonld';
                }
            }
        }

        // Layer B: Open Graph meta tags
        $ogTitle = $this->getMeta($crawler, 'og:title');
        $ogImage = $this->getMeta($crawler, 'og:image');
        $ogDesc = $this->getMeta($crawler, 'og:description');
        $articlePublished = $this->getMeta($crawler, 'article:published_time');
        $articleAuthor = $this->getMeta($crawler, 'article:author');

        if (!isset($extracted['title']) && $ogTitle) {
            $extracted['title'] = $ogTitle;
            $sources['title'] = 'og_tag';
        }
        if (!isset($extracted['subtitle']) && $ogDesc) {
            $extracted['subtitle'] = $ogDesc;
            $sources['subtitle'] = 'og_tag';
        }
        if (!isset($extracted['hero_image_url']) && $ogImage) {
            $extracted['hero_image_url'] = $ogImage;
            $sources['hero_image_url'] = 'og_tag';
        }
        if (!isset($extracted['published_at_raw']) && $articlePublished) {
            $extracted['published_at_raw'] = $articlePublished;
            $sources['published_at'] = 'og_tag';
        }
        if (!isset($extracted['author_raw']) && $articleAuthor) {
            $extracted['author_raw'] = $articleAuthor;
            $sources['author'] = 'og_tag';
        }

        // Layer C: HTML semantic + custom CSS selectors
        $articleExtractors = $config['article_extractors'] ?? [];

        // Title from H1
        if (!isset($extracted['title'])) {
            $titleSelectors = $articleExtractors['title'] ?? ['h1'];
            foreach ($titleSelectors as $sel) {
                try {
                    $node = $crawler->filter($sel)->first();
                    if ($node->count() > 0) {
                        $extracted['title'] = trim($node->text());
                        $sources['title'] = 'html_selector';
                        break;
                    }
                } catch (\Exception) { continue; }
            }
        }

        // Body
        $bodySelectors = $articleExtractors['body'] ?? ['.entry-content', '.post-content', '.article-content', 'article', '.materia-conteudo'];
        foreach ($bodySelectors as $sel) {
            try {
                $node = $crawler->filter($sel)->first();
                if ($node->count() > 0) {
                    $bodyHtml = $node->html();
                    if (mb_strlen(strip_tags($bodyHtml)) > 100) {
                        $extracted['body_html'] = $bodyHtml;
                        $sources['body'] = 'html_selector';
                        break;
                    }
                }
            } catch (\Exception) { continue; }
        }

        // Published date from <time> tags
        if (!isset($extracted['published_at_raw'])) {
            $dateSelectors = $articleExtractors['published_at'] ?? ['time[datetime]', '.post-date', '.published'];
            foreach ($dateSelectors as $sel) {
                try {
                    $node = $crawler->filter($sel)->first();
                    if ($node->count() > 0) {
                        $datetime = $node->attr('datetime') ?? $node->text();
                        if ($datetime) {
                            $extracted['published_at_raw'] = trim($datetime);
                            $sources['published_at'] = 'time_tag';
                            break;
                        }
                    }
                } catch (\Exception) { continue; }
            }
        }

        // Author
        if (!isset($extracted['author_raw'])) {
            $authorSelectors = $articleExtractors['author'] ?? ['a[rel="author"]', '.author-name', '.author'];
            foreach ($authorSelectors as $sel) {
                try {
                    $node = $crawler->filter($sel)->first();
                    if ($node->count() > 0) {
                        $extracted['author_raw'] = trim($node->text());
                        $sources['author'] = 'html_selector';
                        break;
                    }
                } catch (\Exception) { continue; }
            }
        }

        // Image from body
        if (!isset($extracted['hero_image_url'])) {
            $imageSelectors = $articleExtractors['image'] ?? ['meta[property="og:image"]', '.article-content img', '.entry-content img', 'article img'];
            foreach ($imageSelectors as $sel) {
                try {
                    $node = $crawler->filter($sel)->first();
                    if ($node->count() > 0) {
                        $src = $node->attr('content') ?? $node->attr('data-src') ?? $node->attr('src');
                        if ($src) {
                            $extracted['hero_image_url'] = $this->urlNormalizer->resolveRelative($src, $baseUrl);
                            $sources['hero_image_url'] = 'html_selector';
                            break;
                        }
                    }
                } catch (\Exception) { continue; }
            }
        }

        // Layer D: Boilerplate cleaning on body
        if (isset($extracted['body_html'])) {
            $boilerplateRules = $config['boilerplate_rules'] ?? [];
            $extracted['body_html'] = $this->boilerplateCleaner->clean($extracted['body_html'], $boilerplateRules);
            $extracted['body_text'] = strip_tags($extracted['body_html']);
        }

        $extracted['field_sources'] = $sources;

        return $extracted;
    }

    private function extractJsonLd(Crawler $crawler): ?array
    {
        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');
            foreach ($scripts as $script) {
                $json = json_decode($script->textContent, true);
                if (!$json) continue;

                // Handle @graph
                if (isset($json['@graph'])) {
                    foreach ($json['@graph'] as $item) {
                        if (isset($item['@type']) && in_array($item['@type'], ['NewsArticle', 'Article', 'BlogPosting', 'WebPage'])) {
                            return $item;
                        }
                    }
                }

                if (isset($json['@type']) && in_array($json['@type'], ['NewsArticle', 'Article', 'BlogPosting', 'WebPage'])) {
                    return $json;
                }
            }
        } catch (\Exception) {}

        return null;
    }

    private function getMeta(Crawler $crawler, string $property): ?string
    {
        try {
            $node = $crawler->filter("meta[property='{$property}']")->first();
            if ($node->count() > 0) {
                return $node->attr('content');
            }
            // Try name attribute
            $node = $crawler->filter("meta[name='{$property}']")->first();
            if ($node->count() > 0) {
                return $node->attr('content');
            }
        } catch (\Exception) {}

        return null;
    }
}
