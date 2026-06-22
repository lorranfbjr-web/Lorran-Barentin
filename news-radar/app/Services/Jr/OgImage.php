<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;

/**
 * Pega a og:image (foto de capa, a que aparece ao compartilhar o link no
 * WhatsApp) de uma URL. Uma foto por portal. Timeout CURTO e à prova de falha:
 * Cloudflare/erro/sem og:image → devolve null e o fluxo segue sem a foto.
 * NUNCA lança — sempre retorna ?string.
 */
class OgImage
{
    /** @return ?string URL absoluta da og:image, ou null se não houver/der erro. */
    public static function de(string $url, int $timeout = 7): ?string
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $r = Http::timeout($timeout)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; JRbot/1.0; +https://jornaldetijucas.com.br)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->withOptions(['allow_redirects' => true])
                ->get($url);

            if (! $r->successful()) {
                return null;
            }
            // só o <head> basta — corta pra não varrer página inteira.
            $html = mb_substr($r->body(), 0, 120000);
        } catch (\Throwable $e) {
            return null;
        }

        $img = self::metaConteudo($html, 'og:image:secure_url')
            ?? self::metaConteudo($html, 'og:image:url')
            ?? self::metaConteudo($html, 'og:image')
            ?? self::metaConteudo($html, 'twitter:image')
            ?? self::metaConteudo($html, 'twitter:image:src');

        if ($img === null || trim($img) === '') {
            return null;
        }
        $img = html_entity_decode(trim($img), ENT_QUOTES | ENT_HTML5);

        return self::absolutizar($img, $url);
    }

    /** Lê o content de uma <meta property|name="X"> nas duas ordens de atributo. */
    private static function metaConteudo(string $html, string $prop): ?string
    {
        $p = preg_quote($prop, '#');
        // property/name antes do content
        if (preg_match('#<meta[^>]+(?:property|name)\s*=\s*["\']' . $p . '["\'][^>]+content\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
            return $m[1];
        }
        // content antes do property/name
        if (preg_match('#<meta[^>]+content\s*=\s*["\']([^"\']+)["\'][^>]+(?:property|name)\s*=\s*["\']' . $p . '["\']#i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Resolve URL relativa/protocol-relative contra a página de origem. */
    private static function absolutizar(string $img, string $base): ?string
    {
        if (preg_match('#^https?://#i', $img)) {
            return $img;
        }
        if (str_starts_with($img, '//')) {
            return 'https:' . $img;
        }
        $p = parse_url($base);
        if (empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        $root = $p['scheme'] . '://' . $p['host'];
        if (str_starts_with($img, '/')) {
            return $root . $img;
        }

        return $root . '/' . ltrim($img, '/');
    }
}
