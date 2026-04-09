<?php

namespace App\Modules\NewsRadar\Services;

class UrlNormalizerService
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'ref', 'mc_cid', 'mc_eid', 'guccounter',
        'guce_referrer', 'guce_referrer_sig', '_ga', '__twitter_impression',
    ];

    public function normalize(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return $url;
        }

        // Lowercase scheme and host
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower($parsed['host']);
        $host = preg_replace('/^www\./', '', $host);

        $path = $parsed['path'] ?? '/';
        // Remove trailing slash (except root)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // Remove tracking query params
        $query = '';
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            foreach (self::TRACKING_PARAMS as $param) {
                unset($params[$param]);
            }
            if (!empty($params)) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        // Remove fragment
        return "{$scheme}://{$host}{$path}{$query}";
    }

    public function hash(string $url): string
    {
        return hash('sha256', $this->normalize($url));
    }

    public function resolveRelative(string $href, string $baseUrl): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }

        $basePath = dirname($base['path'] ?? '/');
        return "{$scheme}://{$host}{$basePath}/{$href}";
    }
}
