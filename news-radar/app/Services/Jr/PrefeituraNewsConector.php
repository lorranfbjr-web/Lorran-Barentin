<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Process;

/**
 * BLOCO 3 (Goal 02/07) — conector de NOTÍCIA institucional de prefeitura.
 * Config-driven (config/prefeitura.php), 4 estratégias: rss · api · regex ·
 * atende64. Forward-first: cada fonte devolve só as ~N mais recentes da
 * listagem; dedup/early-stop é papel do ingest. Scraping educado: curl com UA
 * honesto, 1 req/s por host (sleep no ingest), --max-time 20, sem burlar
 * bloqueio (fonte bloqueada fica ativo=false na config).
 */
class PrefeituraNewsConector
{
    private const UA = 'Mozilla/5.0 (JornalRazao-Radar; +https://jornalrazao.com)';

    private const MESES = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9,
        'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    /**
     * Busca as notícias recentes de UMA fonte da config.
     *
     * @return array<int,array{titulo:string,data_pub:?string,url:string,resumo:?string}>
     */
    public function noticias(array $fonte, int $max = 15): array
    {
        $itens = match ($fonte['estrategia']) {
            'rss' => $this->viaRss($fonte),
            'api' => $this->viaApi($fonte),
            'regex' => $this->viaRegex($fonte),
            'atende64' => $this->viaAtende64($fonte),
            default => [],
        };

        return array_slice($itens, 0, $max);
    }

    // ───────────────────────── estratégias ─────────────────────────

    private function viaRss(array $fonte): array
    {
        $xml = $this->curl($fonte['url'], $fonte);
        if (! $xml) {
            return [];
        }
        $doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NOERROR);
        if (! $doc) {
            return [];
        }

        $out = [];
        foreach ($doc->channel->item ?? [] as $it) {
            $titulo = $this->limpa((string) $it->title);
            // feed do coda (Tijucas) prefixa "Notícias - " no title
            $titulo = preg_replace('/^Not[íi]cias\s*-\s*/u', '', $titulo);
            $url = trim((string) $it->link);
            if ($titulo === '' || $url === '') {
                continue;
            }
            $out[] = [
                'titulo' => $titulo,
                'data_pub' => $this->dataParaIso((string) $it->pubDate),
                'url' => $url,
                'resumo' => $this->limpa(strip_tags((string) $it->description)) ?: null,
            ];
        }

        return $out;
    }

    /** JSON aberto (mesmo vendor em BC e Itajaí): {noticias:[{newsId,title,subtitle,published}]} */
    private function viaApi(array $fonte): array
    {
        $raw = $this->curl($fonte['url'], $fonte);
        $json = $raw ? json_decode($raw, true) : null;
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach ((array) ($json['noticias'] ?? []) as $n) {
            $titulo = $this->limpa((string) ($n['title'] ?? ''));
            $id = (string) ($n['newsId'] ?? '');
            if ($titulo === '' || $id === '') {
                continue;
            }
            $url = str_replace(['{id}', '{slug}'], [$id, $this->slug($titulo)], $fonte['url_noticia']);
            $out[] = [
                'titulo' => $titulo,
                'data_pub' => $this->dataParaIso((string) ($n['published'] ?? '')),
                'url' => $url,
                'resumo' => $this->limpa((string) ($n['subtitle'] ?? '')) ?: null,
            ];
        }

        return $out;
    }

    private function viaRegex(array $fonte): array
    {
        $html = $this->curl($fonte['url'], $fonte);
        if (! $html) {
            return [];
        }
        if (! empty($fonte['charset']) && strtoupper($fonte['charset']) !== 'UTF-8') {
            $html = @iconv($fonte['charset'], 'UTF-8//IGNORE', $html) ?: $html;
        }
        if (! preg_match_all($fonte['pattern'], $html, $ms, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        foreach ($ms as $m) {
            $titulo = $this->limpa($m['titulo'] ?? '');
            $url = trim(stripslashes($m['url'] ?? ''));
            if ($titulo === '' || $url === '') {
                continue;
            }
            if (! str_starts_with($url, 'http')) {
                $url = rtrim($fonte['base'] ?? '', '/') . '/' . ltrim($url, '/');
            }
            $out[] = [
                'titulo' => $titulo,
                'data_pub' => $this->dataParaIso($m['data'] ?? ''),
                'url' => $url,
                'resumo' => null,
            ];
        }

        return $out;
    }

    /** Atende.net v2 (Indaial): <consulta rotina="49348" ... dados="BASE64"> com JSON. */
    private function viaAtende64(array $fonte): array
    {
        $html = $this->curl($fonte['url'], $fonte);
        if (! $html) {
            return [];
        }
        // atributos vêm em qualquer ordem (dados= antes de rotina= no Indaial)
        $dados = null;
        if (preg_match_all('/<consulta\b[^>]*>/', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (str_contains($tag, 'rotina="' . $fonte['rotina'] . '"')
                    && preg_match('/\bdados="([A-Za-z0-9+\/=]+)"/', $tag, $m)) {
                    $dados = $m[1];
                    break;
                }
            }
        }
        if ($dados === null) {
            return [];
        }
        $json = json_decode(base64_decode($dados) ?: '', true);
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach ((array) ($json['registros'] ?? []) as $r) {
            $titulo = $this->limpa((string) ($r['titulo'] ?? ''));
            $url = trim((string) ($r['url'] ?? ''));
            if ($titulo === '' || $url === '') {
                continue;
            }
            if (! str_starts_with($url, 'http')) {
                $url = rtrim($fonte['base'], '/') . '/' . ltrim($url, '/');
            }
            $out[] = [
                'titulo' => $titulo,
                'data_pub' => $this->dataParaIso((string) ($r['data_publicacao'] ?? $r['data'] ?? '')),
                'url' => $url,
                'resumo' => $this->limpa((string) ($r['chamada'] ?? '')) ?: null,
            ];
        }

        return $out;
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Normaliza data de QUALQUER formato visto no scoping pra ISO YYYY-MM-DD
     * (ou null): ISO/RFC-822 (RSS), "dd/mm/aaaa[ - HH:MM]", "2 de julho de
     * 2026". O clamp futuro/implausível é do Recencia::sanitizar no ingest.
     */
    public function dataParaIso(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/(\d{1,2})\s+de\s+([a-zç]+)\s+de\s+(\d{4})/iu', $s, $m)) {
            $mes = self::MESES[mb_strtolower($m[2])] ?? null;
            if ($mes) {
                return sprintf('%04d-%02d-%02d', $m[3], $mes, $m[1]);
            }
        }
        $ts = strtotime($s); // RFC-822 do RSS ("Wed, 02 Jul 2026 15:08:46 +0000")
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** GET com UA honesto; cookie_gate = 2 passos (1º GET 403 seta cookie, 2º passa). */
    private function curl(string $url, array $fonte): ?string
    {
        $args = ['curl', '-sL', '--max-time', '20', '-A', self::UA];
        if (! empty($fonte['cookie_gate'])) {
            $jar = tempnam(sys_get_temp_dir(), 'jrpref_');
            try {
                Process::timeout(25)->run([...$args, '-c', $jar, '-o', '/dev/null', $url]);
                sleep(1); // educado com o gate
                $r = Process::timeout(25)->run([...$args, '-b', $jar, $url]);

                return $r->successful() && trim($r->output()) !== '' ? $r->output() : null;
            } finally {
                @unlink($jar);
            }
        }

        $r = Process::timeout(25)->run([...$args, $url]);

        return $r->successful() && trim($r->output()) !== '' ? $r->output() : null;
    }

    private function limpa(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function slug(string $titulo): string
    {
        $s = mb_strtolower($titulo);
        if (class_exists('Normalizer')) {
            $s = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D));
        }
        $s = preg_replace('/[&#,+()$~%.\'":*?<>{}]/', '', $s);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }
}
