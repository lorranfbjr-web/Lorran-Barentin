<?php

namespace App\Modules\NewsRadar\Services;

use Illuminate\Support\Facades\Http;

class HttpFetchResult
{
    public function __construct(
        public readonly string $body,
        public readonly int $statusCode,
        public readonly array $headers = [],
        public readonly ?string $effectiveUrl = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}

class HttpFetchService
{
    // 2026-06-10: UA de browser no lugar do 'JornalRazaoBot/1.0' — WAFs de
    // fontes regionais (agorasul, vvale, oestescnoticias…) devolvem 403 pra
    // bot desconhecido. Amostra de 14 fontes ativas validada com os dois UAs
    // antes da troca (nenhuma regressão). Detalhe no _tmp_pos2_*/relatorio.md.
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36';
    private const TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 10;

    public function fetch(string $url, array $options = []): HttpFetchResult
    {
        $headers = array_merge([
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
        ], $options['headers'] ?? []);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);

            $body = $response->body();
            $body = $this->normalizeEncoding($body, $response->header('Content-Type'));

            return new HttpFetchResult(
                body: $body,
                statusCode: $response->status(),
                headers: $response->headers(),
                effectiveUrl: $response->effectiveUri()?->__toString() ?? $url,
            );
        } catch (\Exception $e) {
            // Retry once with Connection: close
            try {
                $headers['Connection'] = 'close';
                $response = Http::withHeaders($headers)
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->get($url);

                $body = $this->normalizeEncoding($response->body(), $response->header('Content-Type'));

                return new HttpFetchResult(
                    body: $body,
                    statusCode: $response->status(),
                    headers: $response->headers(),
                    effectiveUrl: $url,
                );
            } catch (\Exception) {
                throw $e;
            }
        }
    }

    public function fetchXml(string $url): HttpFetchResult
    {
        return $this->fetch($url, [
            'headers' => [
                'Accept' => 'application/rss+xml,application/xml,text/xml,application/atom+xml;q=0.9',
            ],
        ]);
    }

    private function normalizeEncoding(string $body, ?string $contentType): string
    {
        $charset = null;

        // Check Content-Type header
        if ($contentType && preg_match('/charset=([^\s;]+)/i', $contentType, $m)) {
            $charset = strtolower(trim($m[1]));
        }

        // Check HTML meta tag
        if (!$charset && preg_match('/<meta[^>]+charset=["\']*([^"\'\s;>]+)/i', $body, $m)) {
            $charset = strtolower(trim($m[1]));
        }

        if ($charset && !in_array($charset, ['utf-8', 'utf8'])) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if ($converted !== false) {
                // 2026-06-23: o corpo agora é UTF-8, mas o <meta charset> antigo (ex.: Cruzeiro
                // do Vale = iso-8859-1) faria o DomCrawler RE-converter e quebrar acentos
                // ("rÃ¡pido"). Reescreve o charset declarado nas metas pra utf-8. Só roda em
                // páginas que de fato foram convertidas — corpos UTF-8 saem byte-idênticos.
                $converted = preg_replace(
                    '/(<meta[^>]*charset=["\']?)[a-z0-9_-]+/i',
                    '${1}utf-8',
                    $converted
                );
                return $converted;
            }
        }

        // Remove BOM
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        return $body;
    }
}
