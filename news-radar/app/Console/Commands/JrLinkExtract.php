<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Camada de extração de links: pega URLs das capturas e devolve markdown limpo
 * + metadados. trafilatura (default, local) com fallback automático Jina Reader
 * (JS/PDF). Aditivo; não toca produção; não chama API paga.
 */
class JrLinkExtract extends Command
{
    protected $signature = 'jrlink:extract {--limit=20 : Quantas URLs recentes processar} {--print-only}';

    protected $description = 'Extrai markdown+metadados das URLs das capturas (trafilatura + fallback Jina) e gera relatório. Não publica nem envia.';

    private const PY = '/home/jr/jr-extract/venv/bin/python';
    private const SCRIPT = '/home/jr/jr-extract/jr_extract.py';

    private array $newsDomains = [
        'nsctotal', 'sjagora', 'panoramanoticiassc', 'carneironews', 'g1.globo', 'glo.bo',
        'gshow', 'ndmais', 'ndtv', 'clicrbs', 'diariocatarinense', 'folha', 'oglobo', 'uol',
        'metropoles', 'cnnbrasil', 'r7.com', 'terra.com', 'otempo', 'band.com', 'cartacapital',
        'jornaldetijucas', 'jornalrazao', 'sccomvoce', 'horadesc', 'ocp.news', 'munira', 'noticias',
    ];
    private array $govSignals = ['.gov.br', 'prefeitura', 'camara', '.sc.gov', 'pmsc', 'leg.br', 'tjsc', 'mpsc', '.gov', 'emasa'];
    private array $socialDomains = ['facebook.', 'instagram.', 'whatsapp.', 'youtube.', 'youtu.be', 'tiktok.', 'twitter.', 'x.com', 't.me', 'chat.whatsapp'];

    public function handle(): int
    {
        if (! $this->option('print-only')) {
            $urls = $this->coletarUrls((int) $this->option('limit'));
            $this->info('URLs a processar: ' . count($urls));
            $jinaKey = env('JINA_API_KEY');
            $jinaSemChave = empty($jinaKey);

            foreach ($urls as $i => $row) {
                $url = $row['url'];
                $this->line(sprintf('[%d/%d] %s', $i + 1, count($urls), mb_strimwidth($url, 0, 70, '…')));
                $res = $this->trafilatura($url);
                $metodo = 'trafilatura';
                $status = $res['ok'] ? 'ok' : 'vazio';

                if (! $res['ok']) {
                    // fallback Jina
                    $jina = $this->jina($url, $jinaKey);
                    if ($jina['ok']) {
                        $res = $jina;
                        $metodo = 'jina';
                        $status = 'ok';
                    } else {
                        $metodo = $jina['tentou'] ? 'jina' : 'trafilatura';
                        $status = ($res['char_len'] > 0) ? 'vazio' : 'erro';
                    }
                    if ($jinaSemChave && $jina['tentou']) {
                        usleep(3_500_000); // 3,5s: limite 20 req/min sem chave
                    }
                }

                DB::table('jr_link_extracao')->upsert([[
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                    'fonte_tipo' => $row['fonte_tipo'],
                    'categoria' => $this->categoria($url),
                    'metodo' => $metodo,
                    'titulo' => $res['title'] ?? null,
                    'data_pub' => $res['date'] ?? null,
                    'autor' => $res['author'] ?? null,
                    'char_len' => (int) ($res['char_len'] ?? 0),
                    'status' => $status,
                    'markdown' => $res['text'] ?? null,
                    'created_at' => Carbon::now(),
                ]], ['url_hash'], [
                    'url', 'fonte_tipo', 'categoria', 'metodo', 'titulo', 'data_pub', 'autor',
                    'char_len', 'status', 'markdown', 'created_at',
                ]);
            }
        }

        $txt = $this->relatorioTexto();
        $this->line($txt);
        $htmlPath = public_path('_tmp_jrlink/extract.html');
        if (! is_dir(dirname($htmlPath))) mkdir(dirname($htmlPath), 0775, true);
        file_put_contents($htmlPath, $this->html($txt));
        $this->newLine();
        $this->info('HTML: ' . $htmlPath);
        return self::SUCCESS;
    }

    /** Coleta URLs http(s) das capturas, dedup, N mais recentes. */
    private function coletarUrls(int $limit): array
    {
        $caps = DB::table('jr_pauta_capturas')
            ->whereNotNull('texto')->where('texto', 'like', '%http%')
            ->orderByDesc('momment')
            ->limit(400)->get(['texto', 'fonte_tipo']);

        $seen = [];
        $out = [];
        foreach ($caps as $c) {
            if (! preg_match_all('#https?://[^\s\]\)\}"\'<>]+#u', $c->texto, $m)) {
                continue;
            }
            foreach ($m[0] as $u) {
                $u = rtrim($u, ".,;:!?）)]}'\"");
                $h = hash('sha256', $u);
                if (isset($seen[$h])) continue;
                $seen[$h] = true;
                $out[] = ['url' => $u, 'fonte_tipo' => $c->fonte_tipo];
                if (count($out) >= $limit) return $out;
            }
        }
        return $out;
    }

    private function trafilatura(string $url): array
    {
        try {
            $r = Process::timeout(50)->run([self::PY, self::SCRIPT, $url]);
            $json = json_decode(trim($r->output()), true);
            if (is_array($json)) return $json;
        } catch (\Throwable $e) {
            // cai pro retorno padrão
        }
        return ['title' => null, 'author' => null, 'date' => null, 'text' => '', 'char_len' => 0, 'ok' => false];
    }

    private function jina(string $url, ?string $key): array
    {
        $headers = ['X-Return-Format' => 'markdown'];
        if (! empty($key)) $headers['Authorization'] = 'Bearer ' . $key;
        try {
            $resp = Http::withHeaders($headers)->timeout(45)->get('https://r.jina.ai/' . $url);
            $body = $resp->body();
            $ok = $resp->successful() && mb_strlen(trim($body)) >= 300;
            return [
                'tentou' => true, 'ok' => $ok,
                'title' => $this->primeiroTitulo($body),
                'author' => null, 'date' => null,
                'text' => $body, 'char_len' => mb_strlen($body),
            ];
        } catch (\Throwable $e) {
            return ['tentou' => true, 'ok' => false, 'title' => null, 'author' => null,
                'date' => null, 'text' => '', 'char_len' => 0];
        }
    }

    private function primeiroTitulo(string $md): ?string
    {
        if (preg_match('/^Title:\s*(.+)$/m', $md, $m)) return trim($m[1]);
        if (preg_match('/^#\s+(.+)$/m', $md, $m)) return trim($m[1]);
        return null;
    }

    private function categoria(string $url): string
    {
        $u = mb_strtolower($url);
        foreach ($this->socialDomains as $s) { if (str_contains($u, $s)) return 'social'; }
        foreach ($this->govSignals as $g) { if (str_contains($u, $g)) return 'primaria'; }
        foreach ($this->newsDomains as $n) { if (str_contains($u, $n)) return 'concorrente'; }
        return 'outro';
    }

    private function relatorioTexto(): string
    {
        $rows = DB::table('jr_link_extracao')->orderByDesc('created_at')->get();
        $L = [];
        $L[] = '################################################################';
        $L[] = '   JR LINK — EXTRAÇÃO DE CONTEÚDO (trafilatura + Jina fallback)';
        $L[] = '   ' . $rows->count() . ' links processados';
        $L[] = '################################################################';

        // resumo
        $porMetodo = []; $porCat = []; $porStatus = [];
        foreach ($rows as $r) {
            $porMetodo[$r->metodo] = ($porMetodo[$r->metodo] ?? 0) + 1;
            $porCat[$r->categoria] = ($porCat[$r->categoria] ?? 0) + 1;
            $porStatus[$r->status] = ($porStatus[$r->status] ?? 0) + 1;
        }
        $L[] = '';
        $L[] = 'RESUMO:';
        $L[] = '  por método : ' . $this->kv($porMetodo);
        $L[] = '  por status : ' . $this->kv($porStatus);
        $L[] = '  por categoria: ' . $this->kv($porCat);
        $prim = ($porCat['primaria'] ?? 0);
        $conc = ($porCat['concorrente'] ?? 0);
        $L[] = '  >>> fonte PRIMÁRIA (pode reescrever): ' . $prim . '   |   CONCORRENTE/RADAR (não reescreve): ' . $conc;

        foreach ($rows as $i => $r) {
            $marca = match ($r->categoria) {
                'concorrente' => '🚫 RADAR (site_noticias — NÃO reescreve, é plágio)',
                'primaria' => '✅ FONTE PRIMÁRIA (pode reescrever)',
                'social' => '📱 REDE SOCIAL',
                default => '◽ OUTRO',
            };
            $L[] = '';
            $L[] = '================================================================';
            $L[] = sprintf('LINK %02d/%02d   [%s]', $i + 1, $rows->count(), strtoupper($r->status));
            $L[] = $marca;
            $L[] = 'URL......: ' . $r->url;
            $L[] = 'fonte_tipo (captura): ' . ($r->fonte_tipo ?? '-') . '  |  categoria(domínio): ' . $r->categoria;
            $L[] = 'método...: ' . $r->metodo . '  |  chars: ' . $r->char_len;
            $L[] = 'título...: ' . ($r->titulo ?? '(não extraído)');
            $L[] = 'data.....: ' . ($r->data_pub ?? '-') . '  |  autor: ' . ($r->autor ?? '-');
            $L[] = '';
            $L[] = 'MARKDOWN (primeiros ~600 chars):';
            $md = trim((string) $r->markdown);
            $L[] = $md === '' ? '   (vazio)' : '   ' . str_replace("\n", "\n   ", mb_strimwidth($md, 0, 600, '…'));
        }
        $L[] = '';
        $L[] = '================================================================';
        return implode("\n", $L);
    }

    private function kv(array $a): string
    {
        arsort($a);
        $p = [];
        foreach ($a as $k => $v) $p[] = "$k=$v";
        return implode('  ', $p);
    }

    private function html(string $txt): string
    {
        $e = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow, noarchive">'
            . '<title>JR Link — Extração</title>'
            . '<style>body{margin:0;background:#0f1419;color:#e8e8e8;font:13px/1.55 ui-monospace,Menlo,Consolas,monospace}'
            . '.wrap{max-width:1000px;margin:0 auto;padding:14px}pre{white-space:pre-wrap;word-wrap:break-word;margin:0}'
            . 'h1{font:600 18px system-ui;margin:6px 0 12px}</style></head><body><div class="wrap">'
            . '<h1>🔗 JR Link — Extração de conteúdo</h1><pre>' . $e . '</pre></div></body></html>';
    }
}
