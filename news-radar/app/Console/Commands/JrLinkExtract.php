<?php

namespace App\Console\Commands;

use App\Services\Jr\PautaClassifier;
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

    private PautaClassifier $clf;

    public function handle(): int
    {
        $this->cfg = config('jrlink');
        $this->clf = new PautaClassifier($this->cfg);

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
            $host = $this->clf->resolveHost($url, $markdown);
            $categoria = $this->clf->categoria($host);
            $corpo = $this->clf->corpoFromMarkdown($markdown);
            $cats = $this->clf->categoriesFromMarkdown($markdown);
            $status = $this->clf->gateStatus($categoria, $titulo, $corpo);
            [$eixo, $temperatura, $score] = $this->clf->temperatura($categoria, $titulo, $corpo, $cats, $url);

            DB::table('jr_link_extracao')->upsert([[
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'url_norm' => $this->clf->normalizeUrl($url),
                'host' => $host,
                'fonte_tipo' => $row['fonte_tipo'],
                'origem' => 'whatsapp',
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
                'url', 'url_norm', 'host', 'fonte_tipo', 'origem', 'categoria', 'eixo', 'temperatura',
                'score', 'metodo', 'titulo', 'data_pub', 'autor', 'char_len', 'status',
                'markdown', 'created_at',
            ]);
        }
    }

    /**
     * Reaplica categoria/status/host/url_norm a partir do que já está salvo.
     * SÓ rows do WhatsApp — feed (news_items) usa o adaptador da ponte (categoria
     * default=concorrente, régua concorrente_feed); reclassificar feed aqui aplicaria
     * a semântica errada. Reclass de feed = re-rodar `jrlink:bridge-news` (sem fetch).
     */
    private function reclassPass(): void
    {
        $rows = DB::table('jr_link_extracao')
            ->where(function ($q) {
                $q->where('origem', 'whatsapp')->orWhereNull('origem');
            })
            ->orderBy('id')->get();
        foreach ($rows as $r) {
            $host = $this->clf->resolveHost($r->url, $r->markdown);
            $categoria = $this->clf->categoria($host);
            $corpo = $this->clf->corpoFromMarkdown($r->markdown);
            $cats = $this->clf->categoriesFromMarkdown($r->markdown);
            $status = $this->clf->gateStatus($categoria, $r->titulo, $corpo);
            [$eixo, $temperatura, $score] = $this->clf->temperatura($categoria, $r->titulo, $corpo, $cats, $r->url);
            DB::table('jr_link_extracao')->where('id', $r->id)->update([
                'host' => $host,
                'url_norm' => $this->clf->normalizeUrl($r->url),
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

    // ───────────────────────── dedup ─────────────────────────

    /** Marca como duplicada toda URL normalizada repetida, preservando a mais antiga. */
    private function dedupPass(): void
    {
        $rows = DB::table('jr_link_extracao')->orderBy('id')->get(['id', 'url', 'url_norm']);
        $canonico = [];
        foreach ($rows as $r) {
            $norm = $r->url_norm ?: $this->clf->normalizeUrl($r->url);
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

    // ───────────────────────── notificação isolada ─────────────────────────

    /**
     * Caminho ISOLADO — não encosta no disparador (g6ApLgldIyHKLwdq) nem no
     * #JR PUBLICAR. Monta o aviso das pautas quentes com rótulo por eixo e faz
     * POST p/ JRLINK_ALERT_WEBHOOK. DRY-RUN: env vazia => só loga o que mandaria.
     */
    private function notificar(): void
    {
        $quentes = $this->quentesFinais()->take(self::REPORT_CAP);

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

    private const REPORT_CAP = 50;

    /**
     * Quentes FINAIS (Fase 2): temperatura do juiz quando julgado, coarse senão.
     * História clusterizada conta UMA vez (só o representante; sem juiz, membro
     * não-representante de cluster não entra). Ordena por score_editorial
     * (0-100, juiz) e depois score coarse.
     */
    private function quentesFinais()
    {
        return DB::table('jr_link_extracao')
            ->whereRaw("coalesce(temperatura_juiz, temperatura) = 'quente'")
            ->where('duplicada', false)
            ->where(function ($q) {
                $q->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->orderByRaw('coalesce(score_editorial, -1) desc')
            ->orderByDesc('score')->orderByDesc('id')
            ->get();
    }

    private function relatorioTexto(): string
    {
        // Resumo é sobre TODO o conjunto; o detalhe lista só os QUENTES não-dup (cap).
        $total = DB::table('jr_link_extracao')->count();
        $nDup = DB::table('jr_link_extracao')->where('duplicada', true)->count();

        $porCat = $this->contagem('categoria');
        $porStatus = $this->contagem('status');
        $porOrigem = $this->contagem('origem');

        $quentes = $this->quentesFinais();
        $qPrim = $quentes->where('eixo', 'primaria');
        $qConc = $quentes->where('eixo', 'concorrente');

        $nJulgados = DB::table('jr_link_extracao')->whereNotNull('juiz_julgado_em')->count();
        $nMortosJuiz = DB::table('jr_link_extracao')
            ->where('temperatura', 'quente')->where('temperatura_juiz', 'frio')->count();
        $filaHumana = DB::table('jr_link_extracao')
            ->where('temperatura_juiz', 'fila_humana')->where('duplicada', false)
            ->orderByRaw('coalesce(score_editorial, -1) desc')->get();
        $clusterSizes = DB::table('jr_link_extracao')
            ->whereNotNull('cluster_id')->selectRaw('cluster_id, count(*) c')
            ->groupBy('cluster_id')->pluck('c', 'cluster_id');

        $L = [];
        $L[] = '################################################################';
        $L[] = '   JR LINK — PAUTAS QUENTES (WhatsApp + feeds NewsRadar)';
        $L[] = '   gerado: ' . Carbon::now()->format('d/m/Y H:i');
        $L[] = '################################################################';
        $L[] = '';
        $L[] = sprintf('CONTAGEM:  processados=%d   ·   🔥 quentes(não-dup)=%d   ·   ⟂ duplicadas=%d',
            $total, $quentes->count(), $nDup);
        $L[] = sprintf('           ✍️ primária pronta=%d   ·   📡 radar apurar=%d', $qPrim->count(), $qConc->count());
        if ($nJulgados > 0) {
            $L[] = sprintf('JUIZ LLM:  julgados=%d   ·   🧊 quentes-coarse mortos pelo juiz=%d   ·   🙋 fila humana=%d',
                $nJulgados, $nMortosJuiz, $filaHumana->count());
        }
        $L[] = '';
        $L[] = 'RESUMO (todo o conjunto):';
        $L[] = '  por categoria: ' . $this->kv($porCat);
        $L[] = '  por status...: ' . $this->kv($porStatus);
        $L[] = '  por origem...: ' . $this->kv($porOrigem);
        $L[] = '';
        $L[] = 'LEGENDA: ✅ primária pode reescrever · 🚫 concorrente RADAR (NUNCA reescreve) · status ok/parcial/vazio';
        if ($quentes->count() > self::REPORT_CAP) {
            $L[] = '';
            $L[] = sprintf('⚠️  Exibindo top %d quentes por score (de %d).', self::REPORT_CAP, $quentes->count());
        }

        $i = 0;
        $L[] = '';
        $L[] = '████ ✍️ PRIMÁRIA — pronta pra escrever ████';
        foreach ($qPrim->take(self::REPORT_CAP) as $r) {
            $i++;
            $this->blocoQuente($L, $i, $r, $clusterSizes);
        }
        if ($qPrim->isEmpty()) {
            $L[] = '   (nenhuma)';
        }

        $L[] = '';
        $L[] = '████ 📡 RADAR — apurar por conta (NÃO reescrever) ████';
        foreach ($qConc->take(self::REPORT_CAP) as $r) {
            $i++;
            $this->blocoQuente($L, $i, $r, $clusterSizes);
        }
        if ($qConc->isEmpty()) {
            $L[] = '   (nenhuma)';
        }

        if ($filaHumana->isNotEmpty()) {
            $L[] = '';
            $L[] = '████ 🙋 FILA HUMANA — solidariedade/vaquinha (decisão do Lorran) ████';
            foreach ($filaHumana->take(self::REPORT_CAP) as $r) {
                $i++;
                $this->blocoQuente($L, $i, $r, $clusterSizes);
            }
        }

        $L[] = '';
        $L[] = '================================================================';

        return implode("\n", $L);
    }

    private function blocoQuente(array &$L, int $i, object $r, $clusterSizes = null): void
    {
        [$marca] = $this->marcador($r->categoria);
        $julgado = ! empty($r->juiz_julgado_em);
        $scoreTxt = $julgado
            ? sprintf('score=%d/100 (coarse %d)', (int) $r->score_editorial, (int) $r->score)
            : sprintf('score=%d', (int) $r->score);
        $L[] = '';
        $L[] = '----------------------------------------------------------------';
        $L[] = sprintf('#%02d  🔥 %s  ·  %s  ·  [%s]', $i, $scoreTxt, $marca, strtoupper($r->status));
        $L[] = 'título: ' . ($r->titulo ?? '(não extraído)');
        $L[] = 'URL...: ' . $r->url;
        $L[] = 'host..: ' . ($r->host ?? '?') . '  ·  origem=' . ($r->origem ?? '-')
            . '  ·  fonte=' . ($r->fonte_tipo ?? '-') . '  ·  data=' . ($r->data_pub ?? '-');
        if ($julgado) {
            $nCluster = $clusterSizes[$r->cluster_id] ?? 1;
            $L[] = sprintf('juiz..: escopo=%s  ·  gancho=%s  ·  cidade=%s  ·  tema=%s%s',
                $r->escopo ?? '-', $r->tipo_gancho ?? '-', $r->cidade_llm ?? '-', $r->tema_ga4 ?? '-',
                $nCluster > 1 ? sprintf('  ·  cluster=%d (1 de %d portais)', (int) $r->cluster_id, $nCluster) : '');
            $L[] = 'motivo: ' . ($r->juiz_motivo ?: '-');
        }
    }

    /** Contagem agrupada por coluna sobre todo o conjunto. */
    private function contagem(string $col): array
    {
        $out = [];
        foreach (DB::table('jr_link_extracao')->select($col, DB::raw('count(*) c'))->groupBy($col)->get() as $g) {
            $out[$g->$col ?? '-'] = $g->c;
        }

        return $out;
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
