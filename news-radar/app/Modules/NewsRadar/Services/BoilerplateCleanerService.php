<?php

namespace App\Modules\NewsRadar\Services;

use Symfony\Component\DomCrawler\Crawler;

class BoilerplateCleanerService
{
    private const GLOBAL_REMOVE_SELECTORS = [
        'style', 'script', 'noscript', 'iframe[src*="facebook"]',
        '.sharedaddy', '.jp-relatedposts', '.yarpp-related',
        '.code-block', '.ads', '[id^="gam_"]',
        '.line-news', '.line-news-detailed', '#related-news',
        '.ocp-post-inline-placeholder',
        '.mpsc-ultimas-noticias-container',
    ];

    private const GLOBAL_REMOVE_TEXT_PATTERNS = [
        '/O post .{0,200} apareceu primeiro em .{0,200}\./u',
        '/Clique aqui e fa[çc]a parte do nosso grupo.{0,100}/u',
        '/Clique aqui e siga tamb[ée]m .{0,200}/u',
        '/Comente e compartilhe/u',
        '/Siga o .{0,100} nas redes sociais/u',
        '/Receba as not[ií]cias .{0,100}/u',
    ];

    public function clean(string $html, array $sourceRules = []): string
    {
        if (empty(trim($html))) return '';

        $crawler = new Crawler("<div id='__wrapper__'>{$html}</div>");
        $dom = $crawler->getNode(0)?->ownerDocument;
        if (!$dom) return $html;

        $wrapper = $dom->getElementById('__wrapper__');
        if (!$wrapper) return $html;

        // Remove global selectors
        $allSelectors = array_merge(
            self::GLOBAL_REMOVE_SELECTORS,
            $sourceRules['remove_selectors'] ?? []
        );

        foreach ($allSelectors as $selector) {
            try {
                $nodes = (new Crawler($wrapper))->filter($selector);
                foreach ($nodes as $node) {
                    $node->parentNode?->removeChild($node);
                }
            } catch (\Exception) {
                continue;
            }
        }

        // Remove WordPress emoji images
        try {
            $imgs = (new Crawler($wrapper))->filter('img');
            foreach ($imgs as $img) {
                $src = $img->getAttribute('src');
                if ($src && (str_contains($src, 's.w.org') || str_contains($src, 'twemoji'))) {
                    $img->parentNode?->removeChild($img);
                }
            }
        } catch (\Exception) {}

        // Get cleaned HTML
        $html = '';
        foreach ($wrapper->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        // Remove text patterns
        $allPatterns = array_merge(
            self::GLOBAL_REMOVE_TEXT_PATTERNS,
            array_map(fn ($p) => "/{$p}/u", $sourceRules['remove_text_patterns'] ?? [])
        );

        foreach ($allPatterns as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        // Remove empty paragraphs
        $html = preg_replace('/<p>\s*(&nbsp;|\xC2\xA0)?\s*<\/p>/i', '', $html) ?? $html;

        // Remove excessive line breaks
        $html = preg_replace('/(<br\s*\/?>){3,}/i', '<br><br>', $html) ?? $html;

        // Apply body stop patterns
        $stopPatterns = $sourceRules['body_stop_text_patterns'] ?? [];
        foreach ($stopPatterns as $stopText) {
            $pos = mb_stripos($html, $stopText);
            if ($pos !== false) {
                $before = substr($html, 0, $pos);
                $lastTagOpen = strrpos($before, '<p');
                if ($lastTagOpen !== false) {
                    $html = substr($html, 0, $lastTagOpen);
                }
            }
        }

        return trim($html);
    }
}
