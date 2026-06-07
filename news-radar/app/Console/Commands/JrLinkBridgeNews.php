<?php

namespace App\Console\Commands;

use App\Services\Jr\PautaClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PONTE news_items (NewsRadar) -> jr_link_extracao, classificando pela MESMA régua
 * quente/frio (PautaClassifier). Caminho ISOLADO: só LÊ news_items/news_sources,
 * NÃO toca a ingestão do NewsRadar, news_sources, nem o disparador n8n.
 *
 * categoria = concorrente por padrão (é RADAR — NUNCA reescreve), exceto host que
 * bata em proprio/primaria pelas listas já existentes. Dedup cross-source via
 * url_norm (mesmo normalizador do WhatsApp) + url_hash = sha256(url_norm).
 *
 * Backfill BOUNDED por --hours (default 48h). --dry só classifica e mostra, sem gravar.
 */
class JrLinkBridgeNews extends Command
{
    protected $signature = 'jrlink:bridge-news '
        . '{--hours=48 : Janela do backfill (published_at_utc >= agora - N horas)} '
        . '{--limit=4000 : Teto de itens processados} '
        . '{--dry : Só classifica e mostra o resultado, sem gravar nem deduplicar}';

    protected $description = 'Classifica news_items recentes pela régua quente/frio e os insere em jr_link_extracao como RADAR. Não toca a ingestão NewsRadar nem o n8n.';

    public function handle(): int
    {
        $clf = new PautaClassifier();
        $hours = (int) $this->option('hours');
        $limit = (int) $this->option('limit');
        $desde = Carbon::now()->subHours($hours);

        $itens = DB::table('news_items as n')
            ->leftJoin('news_sources as s', 's.id', '=', 'n.news_source_id')
            ->whereNull('n.duplicate_of_id')
            ->where(function ($q) use ($desde) {
                $q->where('n.published_at_utc', '>=', $desde)
                  ->orWhere(function ($qq) use ($desde) {
                      $qq->whereNull('n.published_at_utc')->where('n.created_at', '>=', $desde);
                  });
            })
            ->orderByDesc('n.published_at_utc')
            ->limit($limit)
            ->get(['n.title', 'n.body_text', 'n.url', 'n.categories', 'n.published_at_utc',
                'n.published_at_parsed', 's.name as fonte_nome']);

        $this->info(sprintf('Ponte news_items: janela=%dh  ·  itens=%d  ·  modo=%s',
            $hours, $itens->count(), $this->option('dry') ? 'DRY (não grava)' : 'REAL'));

        $stats = ['processados' => 0, 'quente' => 0, 'frio' => 0, 'primaria' => 0, 'concorrente' => 0];
        $quentes = [];
        $rowsParaGravar = [];

        foreach ($itens as $n) {
            $url = (string) $n->url;
            if ($url === '') {
                continue;
            }
            $titulo = $n->title;
            $corpo = (string) $n->body_text;
            $cats = $this->parseCategories($n->categories);
            $host = $clf->hostFromUrl($url);

            // categoria=concorrente por padrão, exceto proprio/primaria pelas listas.
            $categoria = $clf->categoria($host);
            if (! in_array($categoria, ['proprio', 'primaria'], true)) {
                $categoria = 'concorrente';
            }

            $status = $clf->gateStatus($categoria, $titulo, $corpo);
            // Feed usa régua específica p/ concorrente (mais dura); primária mantém a padrão.
            $reguaKey = $categoria === 'concorrente' ? 'concorrente_feed' : null;
            [$eixo, $temperatura, $score] = $clf->temperatura($categoria, $titulo, $corpo, $cats, $url, $reguaKey);

            $stats['processados']++;
            if ($temperatura === 'quente') {
                $stats['quente']++;
                $stats[$eixo] = ($stats[$eixo] ?? 0) + 1;
                $quentes[] = (object) ['score' => $score, 'eixo' => $eixo, 'titulo' => $titulo,
                    'host' => $host, 'fonte' => $n->fonte_nome, 'url' => $url];
            } elseif ($temperatura === 'frio') {
                $stats['frio']++;
            }

            $dataPub = $n->published_at_utc ?? $n->published_at_parsed;
            $rowsParaGravar[] = [
                'url' => $url,
                'url_hash' => $clf->urlHashNorm($url), // sha256(url normalizada) — colapsa cross-source
                'url_norm' => $clf->normalizeUrl($url),
                'host' => $host,
                'fonte_tipo' => $n->fonte_nome,
                'origem' => 'feed',
                'categoria' => $categoria,
                'eixo' => $eixo,
                'temperatura' => $temperatura,
                'score' => $score,
                'metodo' => 'feed',
                'titulo' => $titulo,
                'data_pub' => $dataPub,
                'autor' => null,
                'char_len' => mb_strlen($corpo),
                'status' => $status,
                'markdown' => $corpo,
                'created_at' => Carbon::now(),
            ];
        }

        // Ordena quentes por score p/ exibir.
        usort($quentes, fn ($a, $b) => $b->score <=> $a->score);

        $this->newLine();
        $this->line(sprintf('RESULTADO: processados=%d  ·  🔥 quentes=%d (✍️ primária=%d · 📡 radar=%d)  ·  ❄️ frios=%d',
            $stats['processados'], $stats['quente'], $stats['primaria'] ?? 0, $stats['concorrente'] ?? 0, $stats['frio']));

        $this->mostrarQuentes($quentes);

        if ($this->option('dry')) {
            $this->newLine();
            $this->warn('DRY-RUN — nada gravado. Rode sem --dry para inserir em jr_link_extracao.');

            return self::SUCCESS;
        }

        // Grava em lotes (upsert idempotente por url_hash).
        $gravados = 0;
        foreach (array_chunk($rowsParaGravar, 200) as $chunk) {
            DB::table('jr_link_extracao')->upsert($chunk, ['url_hash'], [
                'url', 'url_norm', 'host', 'fonte_tipo', 'origem', 'categoria', 'eixo',
                'temperatura', 'score', 'metodo', 'titulo', 'data_pub', 'char_len',
                'status', 'markdown',
            ]);
            $gravados += count($chunk);
        }
        $this->info("Gravados/atualizados em jr_link_extracao: {$gravados}");

        // Dedup cross-source + relatório + notificação (DRY) via o pipeline existente.
        $this->newLine();
        $this->info('Rodando dedup + relatório (jrlink:extract --print-only)…');
        $this->call('jrlink:extract', ['--print-only' => true]);

        return self::SUCCESS;
    }

    private function parseCategories($raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($arr) ? array_map(fn ($c) => mb_strtolower((string) $c), $arr) : [];
    }

    private function mostrarQuentes(array $quentes): void
    {
        $prim = array_filter($quentes, fn ($q) => $q->eixo === 'primaria');
        $conc = array_filter($quentes, fn ($q) => $q->eixo === 'concorrente');

        $this->newLine();
        $this->line('✍️ PRIMÁRIA — pronta pra escrever (' . count($prim) . '):');
        foreach (array_slice($prim, 0, 25) as $q) {
            $this->line(sprintf('  [%d] %s  (%s)', $q->score, mb_strimwidth($q->titulo ?? '-', 0, 70), $q->fonte ?? $q->host));
        }
        $this->newLine();
        $this->line('📡 RADAR — apurar por conta, NÃO reescrever (' . count($conc) . '):');
        foreach (array_slice($conc, 0, 25) as $q) {
            $this->line(sprintf('  [%d] %s  (%s)', $q->score, mb_strimwidth($q->titulo ?? '-', 0, 70), $q->fonte ?? $q->host));
        }
    }
}
