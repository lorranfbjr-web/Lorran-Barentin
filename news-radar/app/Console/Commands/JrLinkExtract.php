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
 *
 * O classificador (categoria/status/dedup) é casado contra o HOST RESOLVIDO e o
 * markdown JÁ SALVO — `--reclass` reaplica as regras SEM re-fetch. Regras em
 * config/jrlink.php (allowlist versionada, fácil de estender).
 */
class JrLinkExtract extends Command
{
    protected $signature = 'jrlink:extract '
        . '{--limit=500 : Quantas URLs recentes processar} '
        . '{--print-only : Só regenera o relatório do que já está no banco} '
        . '{--reclass : Reclassifica os registros existentes (categoria/status/dedup) SEM re-fetch}';

    protected $description = 'Extrai markdown+metadados das URLs das capturas (trafilatura + fallback Jina), classifica por host resolvido e gera relatório. Não publica nem envia.';

    private const PY = '/home/jr/jr-extract/venv/bin/python';
    private const SCRIPT = '/home/jr/jr-extract/jr_extract.py';

    private array $cfg = [];

    public function handle(): int
    {
        $this->cfg = config('jrlink');

        if ($this->option('reclass')) {
            $this->info('Reclassificando registros existentes (sem re-fetch)…');
            $this->reclassPass();
        } elseif (! $this->option('print-only')) {
            $this->fetchPass((int) $this->option('limit'));
        }

        // Dedup sempre roda (idempotente) sobre o conjunto atual.
        $this->dedupPass();

        // Notificação isolada (sempre tenta; DRY-RUN se JRLINK_ALERT_WEBHOOK vazia).
        $this->notificar();

        $txt = $this->relatorioTexto();
        $this->line($txt);
        $htmlPath = public_path('_tmp_jrlink/extract.html');
        if (! is_dir(dirname($htmlPath))) {
            mkdir(dirname($htmlPath), 0775, true);
        }
        file_put_contents($htmlPath, $this->html($txt));
        $this->newLine();
        $this->info('HTML: ' . $htmlPath);

        return self::SUCCESS;
    }

    /** Busca URLs novas das capturas e extrai (trafilatura + fallback Jina). */
    private function fetchPass(int $limit): void
    {
        $urls = $this->coletarUrls($limit);
        $this->info('URLs a processar: ' . count($urls));
        $jinaKey = env('JINA_API_KEY');
        $jinaSemChave = empty($jinaKey);

        foreach ($urls as $i => $row) {
            $url = $row['url'];
            $this->line(sprintf('[%d/%d] %s', $i + 1, count($urls), mb_strimwidth($url, 0, 70, '…')));
            $res = $this->trafilatura($url);
            $metodo = 'trafilatura';

            if (! $res['ok']) {
                $jina = $this->jina($url, $jinaKey);
                if ($jina['ok']) {
                    $res = $jina;
                    $metodo = 'jina';
                } else {
                    $metodo = $jina['tentou'] ? 'jina' : 'trafilatura';
                }
                if ($jinaSemChave && $jina['tentou']) {
                    usleep(3_500_000); // 3,5s: limite 20 req/min sem chave
                }
            }

            $markdown = $res['text'] ?? null;
            $titulo = $res['title'] ?? null;
            $host = $this->resolveHost($url, $markdown);
            $categoria = $this->categoria($host);
            $status = $this->gateStatus($categoria, $titulo, $markdown);
            [$eixo, $temperatura, $score] = $this->temperatura($categoria, $titulo, $markdown);

            DB::table('jr_link_extracao')->upsert([[
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'url_norm' => $this->normalizeUrl($url),
                'host' => $host,
                'fonte_tipo' => $row['fonte_tipo'],
                'categoria' => $categoria,
                'eixo' => $eixo,
                'temperatura' => $temperatura,
                'score' => $score,
                'metodo' => $metodo,
                'titulo' => $titulo,
                'data_pub' => $res['date'] ?? null,
                'autor' => $res['author'] ?? null,
                'char_len' => (int) ($res['char_len'] ?? 0),
                'status' => $status,
                'markdown' => $markdown,
                'created_at' => Carbon::now(),
            ]], ['url_hash'], [
                'url', 'url_norm', 'host', 'fonte_tipo', 'categoria', 'eixo', 'temperatura',
                'score', 'metodo', 'titulo', 'data_pub', 'autor', 'char_len', 'status',
                'markdown', 'created_at',
            ]);
        }
    }

    /** Reaplica categoria/status/host/url_norm a partir do que já está salvo. */
    private function reclassPass(): void
    {
        $rows = DB::table('jr_link_extracao')->orderBy('id')->get();
        foreach ($rows as $r) {
            $host = $this->resolveHost($r->url, $r->markdown);
            $categoria = $this->categoria($host);
            $status = $this->gateStatus($categoria, $r->titulo, $r->markdown);
            [$eixo, $temperatura, $score] = $this->temperatura($categoria, $r->titulo, $r->markdown);
            DB::table('jr_link_extracao')->where('id', $r->id)->update([
                'host' => $host,
                'url_norm' => $this->normalizeUrl($r->url),
                'categoria' => $categoria,
                'status' => $status,
                'eixo' => $eixo,
                'temperatura' => $temperatura,
                'score' => $score,
            ]);
            $this->line(sprintf('  #%-3d %-12s %-8s %-7s s=%-2d %s', $r->id, $categoria, $status,
                $temperatura ?? '-', $score, $host ?? '?'));
        }
    }

    /** Coleta URLs http(s) das capturas, dedup por hash bruto, N mais recentes. */
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
                if (isset($seen[$h])) {
                    continue;
                }
                $seen[$h] = true;
                $out[] = ['url' => $u, 'fonte_tipo' => $c->fonte_tipo];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    private function trafilatura(string $url): array
    {
        try {
            $r = Process::timeout(50)->run([self::PY, self::SCRIPT, $url]);
            $json = json_decode(trim($r->output()), true);
            if (is_array($json)) {
                return $json;
            }
        } catch (\Throwable $e) {
            // cai pro retorno padrão
        }

        return ['title' => null, 'author' => null, 'date' => null, 'text' => '', 'char_len' => 0, 'ok' => false];
    }

    private function jina(string $url, ?string $key): array
    {
        $headers = ['X-Return-Format' => 'markdown'];
        if (! empty($key)) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
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
        if (preg_match('/^Title:\s*(.+)$/m', $md, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^#\s+(.+)$/m', $md, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    // ───────────────────────── classificador ─────────────────────────

    /**
     * Host resolvido (final): prioriza o `url:`/`hostname:` do frontmatter do
     * trafilatura ou o `URL Source:` do Jina (que já são o destino do encurtador);
     * cai pro host da URL original. Sempre sem "www.".
     */
    private function resolveHost(string $url, ?string $markdown): ?string
    {
        $md = (string) $markdown;

        // trafilatura: frontmatter YAML "url:" (URL final resolvida)
        if (preg_match('/^url:\s*(\S+)/mi', $md, $m)) {
            if ($h = $this->hostDe($m[1])) {
                return $h;
            }
        }
        // trafilatura: frontmatter "hostname:"
        if (preg_match('/^hostname:\s*(\S+)/mi', $md, $m)) {
            $h = $this->limpaHost($m[1]);
            if ($h !== '') {
                return $h;
            }
        }
        // Jina: "URL Source: https://..."
        if (preg_match('/^URL Source:\s*(\S+)/mi', $md, $m)) {
            if ($h = $this->hostDe($m[1])) {
                return $h;
            }
        }

        return $this->hostDe($url);
    }

    private function hostDe(string $url): ?string
    {
        $h = parse_url($url, PHP_URL_HOST);
        if (! $h) {
            return null;
        }

        return $this->limpaHost($h);
    }

    private function limpaHost(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = preg_replace('/^www\d*\./', '', $h);

        return rtrim($h, '.');
    }

    /** host casa o domínio D se host == D ou termina em ".D". */
    private function hostCasa(string $host, array $dominios): bool
    {
        foreach ($dominios as $d) {
            $d = mb_strtolower($d);
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }

        return false;
    }

    /** Categoria pelo host resolvido: proprio -> social -> primaria -> concorrente -> outro. */
    private function categoria(?string $host): string
    {
        if (! $host) {
            return 'outro';
        }
        if ($this->hostCasa($host, $this->cfg['proprio'] ?? [])) {
            return 'proprio';
        }
        if ($this->hostCasa($host, $this->cfg['social'] ?? [])) {
            return 'social';
        }
        foreach (($this->cfg['gov_suffixes'] ?? []) as $suf) {
            if (str_ends_with($host, mb_strtolower($suf))) {
                return 'primaria';
            }
        }
        foreach (($this->cfg['gov_signals'] ?? []) as $sig) {
            if (str_contains($host, mb_strtolower($sig))) {
                return 'primaria';
            }
        }
        if ($this->hostCasa($host, $this->cfg['concorrente'] ?? [])) {
            return 'concorrente';
        }

        return 'outro';
    }

    /**
     * Gate de qualidade honesto, recalculado do markdown salvo (sem re-fetch).
     *  - social: nunca "ok" — título com conteúdo (legenda) = "parcial"; senão "vazio".
     *  - muro de login / boilerplate detectado: "parcial" (tem título) ou "vazio".
     *  - corpo real de matéria (>= min_corpo_ok): "ok".
     */
    private function gateStatus(string $categoria, ?string $titulo, ?string $markdown): string
    {
        $corpo = $this->corpoLimpo($markdown);
        $temTitulo = $this->tituloUtil($titulo);

        if ($categoria === 'social') {
            return $temTitulo ? 'parcial' : 'vazio';
        }
        if ($this->ehMuro($corpo)) {
            return $temTitulo ? 'parcial' : 'vazio';
        }
        if (mb_strlen(trim($corpo)) >= (int) ($this->cfg['min_corpo_ok'] ?? 300)) {
            return 'ok';
        }

        return $temTitulo ? 'parcial' : 'vazio';
    }

    /** Remove frontmatter YAML (trafilatura) e cabeçalho do Jina, deixa só o corpo. */
    private function corpoLimpo(?string $markdown): string
    {
        $md = trim((string) $markdown);
        if ($md === '') {
            return '';
        }
        // frontmatter YAML --- ... ---
        if (str_starts_with($md, '---')) {
            $parts = preg_split('/^---\s*$/m', $md, 3);
            if (is_array($parts) && count($parts) >= 3) {
                $md = trim($parts[2]);
            }
        }
        // Jina: "Markdown Content:" marca o início do corpo
        if (preg_match('/Markdown Content:\s*(.*)$/s', $md, $m)) {
            $md = trim($m[1]);
        }
        // tira o cabeçalho Title:/URL Source: do Jina, se sobrou
        $md = preg_replace('/^(Title|URL Source|Published Time|Markdown Content):.*$/mi', '', $md);

        return trim((string) $md);
    }

    private function ehMuro(string $corpo): bool
    {
        $c = mb_strtolower($corpo);
        foreach (($this->cfg['walls'] ?? []) as $w) {
            if (str_contains($c, mb_strtolower($w))) {
                return true;
            }
        }

        return false;
    }

    private function tituloUtil(?string $titulo): bool
    {
        $t = mb_strtolower(trim((string) $titulo));
        if (mb_strlen($t) <= 3) {
            return false;
        }

        return ! in_array($t, $this->cfg['generic_titles'] ?? [], true);
    }

    // ───────────────────────── dedup ─────────────────────────

    /**
     * Normaliza a URL p/ dedup: minúsculo no esquema+host, http->https, tira barra
     * final, remove tracking (utm_*, igsh, fbclid, mode, …) e fragmento.
     */
    private function normalizeUrl(string $url): string
    {
        $p = parse_url(trim($url));
        if ($p === false || empty($p['host'])) {
            return mb_strtolower(rtrim(trim($url), '/'));
        }

        $scheme = mb_strtolower($p['scheme'] ?? 'https');
        if ($scheme === 'http') {
            $scheme = 'https';
        }
        $host = $this->limpaHost($p['host']);
        $port = isset($p['port']) && ! in_array((int) $p['port'], [80, 443], true) ? ':' . $p['port'] : '';
        $path = rtrim($p['path'] ?? '', '/');

        $query = '';
        if (! empty($p['query'])) {
            parse_str($p['query'], $q);
            $drop = array_map('mb_strtolower', $this->cfg['tracking_params'] ?? []);
            $kept = [];
            foreach ($q as $k => $v) {
                $lk = mb_strtolower($k);
                if (str_starts_with($lk, 'utm_') || in_array($lk, $drop, true)) {
                    continue;
                }
                $kept[$k] = $v;
            }
            if ($kept) {
                ksort($kept);
                $query = '?' . http_build_query($kept);
            }
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /** Marca como duplicada toda URL normalizada repetida, preservando a mais antiga. */
    private function dedupPass(): void
    {
        $rows = DB::table('jr_link_extracao')->orderBy('id')->get(['id', 'url', 'url_norm']);
        $canonico = [];
        foreach ($rows as $r) {
            $norm = $r->url_norm ?: $this->normalizeUrl($r->url);
            $dup = isset($canonico[$norm]);
            if (! $dup) {
                $canonico[$norm] = $r->id;
            }
            DB::table('jr_link_extracao')->where('id', $r->id)->update([
                'url_norm' => $norm,
                'duplicada' => $dup,
            ]);
        }
    }

    // ───────────────────────── quente / frio ─────────────────────────

    /**
     * Classifica a pauta em dois eixos independentes (config/jrlink.php > reguas).
     * Região é detectada pelo CONTEÚDO (título + markdown), nunca por fonte_cidade.
     *
     * @return array{0:?string,1:?string,2:int} [eixo, temperatura, score]
     */
    private function temperatura(string $categoria, ?string $titulo, ?string $markdown): array
    {
        // Só primária e concorrente viram pauta; os demais não pontuam.
        if (! in_array($categoria, ['primaria', 'concorrente'], true)) {
            return ['-', null, 0];
        }

        $regua = $this->cfg['reguas'][$categoria] ?? [];
        $texto = mb_strtolower(trim(($titulo ?? '') . ' ' . $this->corpoLimpo($markdown)));

        $cidadeHit = $this->contemAlgum($texto, $this->cfg['regiao']['cidades'] ?? []);
        $estadoHit = $this->contemAlgum($texto, $this->cfg['regiao']['estado'] ?? []);
        $ganchoHit = $this->contemAlgum($texto, $this->cfg['temas']['gancho_top']['termos'] ?? []);
        $temaHit = $this->contemAlgum($texto, $this->cfg['temas']['tema_leve']['termos'] ?? []);

        $score = (int) ($regua['base'] ?? 0);
        if ($cidadeHit) {
            $score += (int) ($regua['peso_regiao_cidade'] ?? 0);
        } elseif ($estadoHit) {
            $score += (int) ($regua['peso_regiao_estado'] ?? 0);
        }
        if ($ganchoHit) {
            $score += (int) ($this->cfg['temas']['gancho_top']['peso'] ?? 0);
        }
        if ($temaHit) {
            $score += (int) ($this->cfg['temas']['tema_leve']['peso'] ?? 0);
        }

        // Requisitos duros (eixo concorrente mata ruído nacional).
        $okRequisitos = true;
        if (($regua['exige_regiao'] ?? false) && ! ($cidadeHit || $estadoHit)) {
            $okRequisitos = false;
        }
        if (($regua['exige_gancho'] ?? false) && ! $ganchoHit) {
            $okRequisitos = false;
        }

        $quente = $okRequisitos && $score >= (int) ($regua['corte_quente'] ?? 999);

        return [$categoria, $quente ? 'quente' : 'frio', $score];
    }

    private function contemAlgum(string $texto, array $termos): bool
    {
        foreach ($termos as $t) {
            if ($t !== '' && str_contains($texto, mb_strtolower($t))) {
                return true;
            }
        }

        return false;
    }

    // ───────────────────────── notificação isolada ─────────────────────────

    /**
     * Caminho ISOLADO — não encosta no disparador (g6ApLgldIyHKLwdq) nem no
     * #JR PUBLICAR. Monta o aviso das pautas quentes com rótulo por eixo e faz
     * POST p/ JRLINK_ALERT_WEBHOOK. DRY-RUN: env vazia => só loga o que mandaria.
     */
    private function notificar(): void
    {
        $quentes = DB::table('jr_link_extracao')
            ->where('temperatura', 'quente')
            ->where('duplicada', false)
            ->orderByDesc('score')->orderByDesc('id')
            ->get();

        if ($quentes->isEmpty()) {
            $this->line('Notificação: nenhuma pauta quente — nada a enviar.');

            return;
        }

        $texto = $this->montarAviso($quentes);
        $webhook = env('JRLINK_ALERT_WEBHOOK');

        if (empty($webhook)) {
            $this->warn('Notificação em DRY-RUN (JRLINK_ALERT_WEBHOOK vazia) — NÃO enviado. Mandaria:');
            $this->line($texto);

            return;
        }

        try {
            $resp = Http::timeout(15)->asJson()->post($webhook, [
                'source' => 'jrlink',
                'quentes' => $quentes->count(),
                'text' => $texto,
                'itens' => $quentes->map(fn ($r) => [
                    'eixo' => $r->eixo,
                    'score' => $r->score,
                    'titulo' => $r->titulo,
                    'url' => $r->url,
                    'host' => $r->host,
                ])->all(),
            ]);
            $this->info('Notificação enviada (' . $quentes->count() . ' quentes) — HTTP ' . $resp->status());
        } catch (\Throwable $e) {
            $this->error('Notificação FALHOU (webhook): ' . $e->getMessage());
        }
    }

    private function montarAviso($quentes): string
    {
        $prim = $quentes->where('eixo', 'primaria');
        $conc = $quentes->where('eixo', 'concorrente');
        $L = [];
        $L[] = '🔥 *JR LINK — pautas quentes* (' . $quentes->count() . ')';

        if ($prim->isNotEmpty()) {
            $L[] = '';
            $L[] = '✍️ *PRIMÁRIA — pronta pra escrever* (' . $prim->count() . '):';
            foreach ($prim as $r) {
                $L[] = sprintf('• [%d] %s', $r->score, $r->titulo ?: $r->url);
                $L[] = '  ' . $r->url;
            }
        }
        if ($conc->isNotEmpty()) {
            $L[] = '';
            $L[] = '📡 *RADAR — apurar por conta* (NÃO reescrever) (' . $conc->count() . '):';
            foreach ($conc as $r) {
                $L[] = sprintf('• [%d] %s', $r->score, $r->titulo ?: $r->url);
                $L[] = '  ' . $r->url;
            }
        }

        return implode("\n", $L);
    }

    // ───────────────────────── relatório ─────────────────────────

    private function relatorioTexto(): string
    {
        // Ordena: quentes primeiro, depois maior score, depois id. Duplicadas afundam.
        $rows = DB::table('jr_link_extracao')
            ->orderByRaw("CASE WHEN duplicada = 1 THEN 1 ELSE 0 END asc")
            ->orderByRaw("CASE WHEN temperatura = 'quente' THEN 0 ELSE 1 END asc")
            ->orderByDesc('score')
            ->orderBy('id')
            ->get();
        $total = $rows->count();
        $L = [];
        $L[] = '################################################################';
        $L[] = '   JR LINK — EXTRAÇÃO + CLASSIFICAÇÃO + QUENTE/FRIO';
        $L[] = '   ' . $total . ' links  ·  categoria por HOST RESOLVIDO  ·  pauta por 2 eixos';
        $L[] = '   gerado: ' . Carbon::now()->format('d/m/Y H:i');
        $L[] = '################################################################';

        $porMetodo = [];
        $porCat = [];
        $porStatus = [];
        $nDup = 0;
        $quentes = $rows->where('temperatura', 'quente')->where('duplicada', false);
        $qPrim = $quentes->where('eixo', 'primaria')->count();
        $qConc = $quentes->where('eixo', 'concorrente')->count();
        foreach ($rows as $r) {
            $porMetodo[$r->metodo] = ($porMetodo[$r->metodo] ?? 0) + 1;
            $porCat[$r->categoria] = ($porCat[$r->categoria] ?? 0) + 1;
            $porStatus[$r->status] = ($porStatus[$r->status] ?? 0) + 1;
            if ($r->duplicada) {
                $nDup++;
            }
        }
        $L[] = '';
        $L[] = '🔥 QUENTES: ' . $quentes->count()
            . '   (✍️ primária pronta=' . $qPrim . '  ·  📡 radar apurar=' . $qConc . ')';
        $L[] = '';
        $L[] = 'RESUMO:';
        $L[] = '  por categoria: ' . $this->kv($porCat);
        $L[] = '  por status...: ' . $this->kv($porStatus);
        $L[] = '  por método...: ' . $this->kv($porMetodo);
        $L[] = '  duplicadas...: ' . $nDup;
        $L[] = '';
        $L[] = 'LEGENDA categoria: ✅ primária (pode reescrever) · 🚫 concorrente (RADAR, não reescreve)';
        $L[] = '                   🟦 próprio (jornalrazao, já publicado) · 📱 social · ◽ outro (revisar)';
        $L[] = 'LEGENDA status...: ok (corpo real) · parcial (só título/metadado) · vazio (muro/sem conteúdo)';
        $L[] = 'EIXO pauta.......: ✍️ primária = vira pauta direto · 📡 concorrente = só apurar, NUNCA reescrever';

        $i = 0;
        foreach ($rows as $r) {
            $i++;
            [$marca] = $this->marcador($r->categoria);
            $dup = $r->duplicada ? 'SIM ⟂ (duplicada de outra URL normalizada)' : 'não';
            $temp = $this->temperaturaLabel($r->eixo, $r->temperatura, (int) $r->score);
            $L[] = '';
            $L[] = '================================================================';
            $L[] = sprintf('LINK %02d/%02d   %s', $i, $total, $temp);
            $L[] = sprintf('  categoria: %s   |   status: [%s]   |   dup: %s',
                $marca, strtoupper($r->status), $dup);
            $L[] = 'URL......: ' . $r->url;
            $L[] = 'host.....: ' . ($r->host ?? '?') . '   (norm: ' . ($r->url_norm ?? '-') . ')';
            $L[] = 'captura..: fonte_tipo=' . ($r->fonte_tipo ?? '-') . '  |  método=' . $r->metodo . '  |  chars=' . $r->char_len;
            $L[] = 'título...: ' . ($r->titulo ?? '(não extraído)');
            $L[] = 'data.....: ' . ($r->data_pub ?? '-') . '  |  autor: ' . ($r->autor ?? '-');
            $L[] = '';
            $L[] = 'MARKDOWN (primeiros ~500 chars):';
            $md = trim((string) $r->markdown);
            $L[] = $md === '' ? '   (vazio)' : '   ' . str_replace("\n", "\n   ", mb_strimwidth($md, 0, 500, '…'));
        }
        $L[] = '';
        $L[] = '================================================================';

        return implode("\n", $L);
    }

    private function temperaturaLabel(?string $eixo, ?string $temperatura, int $score): string
    {
        if (! in_array($eixo, ['primaria', 'concorrente'], true)) {
            return 'pauta: — (não pontua)';
        }
        $icone = $temperatura === 'quente' ? '🔥 QUENTE' : '❄️ frio';
        $eixoLabel = $eixo === 'primaria' ? '✍️ PRIMÁRIA (escrever)' : '📡 RADAR (apurar)';

        return sprintf('pauta: %s  ·  %s  ·  score=%d', $icone, $eixoLabel, $score);
    }

    /** @return array{0:string} marcador visível da categoria */
    private function marcador(string $categoria): array
    {
        return [match ($categoria) {
            'primaria' => '✅ primária (pode reescrever)',
            'concorrente' => '🚫 concorrente (RADAR)',
            'proprio' => '🟦 próprio (já publicado)',
            'social' => '📱 social',
            default => '◽ outro (revisar)',
        }];
    }

    private function kv(array $a): string
    {
        arsort($a);
        $p = [];
        foreach ($a as $k => $v) {
            $p[] = "$k=$v";
        }

        return implode('  ', $p);
    }

    private function html(string $txt): string
    {
        $e = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow, noarchive">'
            . '<title>JR Link — Extração + Classificação</title>'
            . '<style>body{margin:0;background:#0f1419;color:#e8e8e8;font:13px/1.55 ui-monospace,Menlo,Consolas,monospace}'
            . '.wrap{max-width:1000px;margin:0 auto;padding:14px}pre{white-space:pre-wrap;word-wrap:break-word;margin:0}'
            . 'h1{font:600 18px system-ui;margin:6px 0 12px}</style></head><body><div class="wrap">'
            . '<h1>🔗 JR Link — Extração + Classificação</h1><pre>' . $e . '</pre></div></body></html>';
    }
}
