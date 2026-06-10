<?php

namespace App\Console\Commands;

use App\Services\Jr\EventClusterer;
use App\Services\Jr\JuizLlm;
use App\Services\Jr\PautaClassifier;
use App\Support\TituloFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FASE 2 — pipeline em camadas sobre jr_link_extracao:
 *   1. pré-corte = gate regional coarse já gravado (eixo primaria/concorrente,
 *      não-dup, janela --hours);
 *   2. colapso por EVENTO (EventClusterer) persistido em news_clusters /
 *      news_cluster_items, 1 representante por história;
 *   3. juiz LLM SÓ nos representantes que valem chamada (quente coarse, ou
 *      frio coarse com score >= juiz.score_frio_minimo).
 *
 * Veredito final por representante:
 *   - eh_pauta=false OU escopo=nacional => temperatura_juiz=frio
 *   - gancho solidariedade/vaquinha     => temperatura_juiz=fila_humana
 *   - score_editorial (= score do LLM + âncora GA4 por tema/gancho) >=
 *     juiz.corte_quente_final => quente; senão frio.
 *
 * Idempotente: representante já julgado com a MESMA prompt_versao não re-julga
 * (--force re-julga). Hard cap de chamadas LLM por execução: aborta ANTES de
 * gastar se o plano estourar juiz.cap_chamadas.
 */
class JrLinkJuiz extends Command
{
    protected $signature = 'jrlink:juiz '
        . '{--hours=48 : Janela (data_pub/created_at >= agora - N horas)} '
        . '{--driver= : Força o driver (openai|claude-cli); default config/auto} '
        . '{--dry : Clusteriza e mostra o plano de julgamento, sem chamar LLM nem gravar} '
        . '{--ids= : Julga SÓ estes ids de jr_link_extracao (separados por vírgula), ignorando idempotência} '
        . '{--force : Re-julga mesmo quem já tem veredito desta prompt_versao}';

    protected $description = 'Camadas 2+3 da Fase 2: colapsa eventos via news_clusters e julga os representantes com o juiz LLM (título+lead, JSON estrito).';

    public function handle(): int
    {
        $cfg = config('jrlink.juiz');
        $hours = (int) $this->option('hours');
        $promptVersao = (string) $cfg['prompt_versao'];

        // ── camada 1: pré-corte (gate coarse já aplicado nas colunas) ──
        $rows = $this->candidatos($hours);
        $this->info(sprintf('Pré-corte: %d itens na janela de %dh (eixo primária/concorrente, não-dup).', count($rows), $hours));
        if (! $rows) {
            return self::SUCCESS;
        }

        // ── camada 2: colapso por evento ──
        $clusterer = new EventClusterer();
        $clusters = $clusterer->cluster($rows);
        $multi = array_filter($clusters, fn ($c) => count($c['ids']) > 1);
        $this->info(sprintf('Colapso por evento: %d histórias (%d clusters com 2+ portais, %d itens colapsados).',
            count($clusters), count($multi), array_sum(array_map(fn ($c) => count($c['ids']) - 1, $multi))));

        $byId = collect($rows)->keyBy('id');

        // ── seleção do juiz: representantes que valem chamada ──
        $aJulgar = [];
        foreach ($clusters as $c) {
            $rep = $byId[$c['rep']];
            $quenteCoarse = collect($c['ids'])->contains(fn ($id) => $byId[$id]->temperatura === 'quente');
            $scoreMax = collect($c['ids'])->max(fn ($id) => (int) $byId[$id]->score);
            if ($quenteCoarse || $scoreMax >= (int) $cfg['score_frio_minimo']) {
                $aJulgar[] = $rep;
            }
        }

        // --ids: re-julga itens específicos (calibração dirigida), sem clusterizar de novo.
        if ($this->option('ids')) {
            $ids = array_map('intval', explode(',', (string) $this->option('ids')));
            $aJulgar = array_values(array_filter($rows, fn ($r) => in_array((int) $r->id, $ids, true)));
        }

        $jaJulgados = ($this->option('force') || $this->option('ids')) ? collect() : collect($aJulgar)
            ->filter(fn ($r) => $r->juiz_prompt_versao === $promptVersao && $r->juiz_julgado_em !== null)
            ->pluck('id');
        $pendentes = array_values(array_filter($aJulgar, fn ($r) => ! $jaJulgados->contains($r->id)));

        $lote = max(1, (int) $cfg['lote']);
        $chamadasPrevistas = (int) ceil(count($pendentes) / $lote);
        $this->info(sprintf('Juiz: %d representantes selecionados · %d já julgados (%s) · %d pendentes · %d chamadas previstas (lote=%d, cap=%d).',
            count($aJulgar), $jaJulgados->count(), $promptVersao, count($pendentes), $chamadasPrevistas, $lote, (int) $cfg['cap_chamadas']));

        // Hard cap: aborta ANTES de gastar.
        if ($chamadasPrevistas > (int) $cfg['cap_chamadas']) {
            $this->error(sprintf('ABORTADO: %d chamadas previstas > cap %d. Suba juiz.cap_chamadas ou estreite a janela.',
                $chamadasPrevistas, (int) $cfg['cap_chamadas']));

            return self::FAILURE;
        }

        if ($this->option('dry')) {
            foreach (array_slice($pendentes, 0, 30) as $r) {
                $this->line(sprintf('  julgaria #%-4d [%s/%d] %s', $r->id, $r->eixo, $r->score, mb_strimwidth((string) $r->titulo, 0, 80)));
            }
            $this->warn('DRY-RUN — nada gravado, nenhuma chamada LLM.');

            return self::SUCCESS;
        }

        // ── persiste clusters (news_clusters + colunas em jr_link_extracao) ──
        $this->persistirClusters($clusters, $byId, $hours);

        // ── camada 3: juiz LLM nos pendentes ──
        $juiz = new JuizLlm(null, $this->option('driver') ?: null);
        $this->info(sprintf('Driver: %s · modelo: %s', $juiz->driver(), $juiz->modelo()));

        $clf = new PautaClassifier();
        $julgados = 0;
        $falhas = 0;
        foreach (array_chunk($pendentes, $lote) as $chunk) {
            $itens = array_map(fn ($r) => [
                'id' => (int) $r->id,
                'titulo' => (string) $r->titulo,
                'lead' => $clf->corpoFromMarkdown($r->markdown),
            ], $chunk);

            try {
                $vereditos = $juiz->julgarLote($itens);
            } catch (\Throwable $e) {
                $falhas++;
                $this->error('Lote falhou: ' . $e->getMessage());
                continue;
            }

            foreach ($chunk as $r) {
                $this->aplicarVeredito($r, $vereditos[(int) $r->id], $juiz, $promptVersao, $cfg);
                $julgados++;
            }
            $this->line(sprintf('  julgados %d/%d…', $julgados, count($pendentes)));
        }

        // ── resumo ──
        $custo = DB::table('jr_juiz_log')->where('created_at', '>=', Carbon::now()->subMinutes(30))->sum('custo_usd');
        $this->newLine();
        $this->info(sprintf('Juiz concluído: %d julgados · %d lotes com falha · custo (últimos 30min): US$ %.4f',
            $julgados, $falhas, (float) $custo));
        $this->tabelaTemperaturas();

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Itens da janela que passaram o gate coarse (camada 1). */
    private function candidatos(int $hours): array
    {
        $cutoff = Carbon::now()->subHours($hours);

        return DB::table('jr_link_extracao')
            ->whereIn('eixo', ['primaria', 'concorrente'])
            ->where('duplicada', false)
            ->where(function ($q) use ($cutoff) {
                // data_pub é string heterogênea; ISO compara lexicograficamente,
                // o resto cai pro created_at.
                $q->where('data_pub', '>=', $cutoff->toDateString())
                    ->orWhere(function ($qq) use ($cutoff) {
                        $qq->whereNull('data_pub')->where('created_at', '>=', $cutoff);
                    })
                    ->orWhere('created_at', '>=', $cutoff);
            })
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Persiste o colapso SÓ da janela corrente: recria em news_clusters/
     * news_cluster_items e grava cluster_id/cluster_rep nas rows da janela.
     * Rows FORA da janela ficam congeladas com a última atribuição (apagar o
     * cluster delas faria membro antigo reaparecer como quente avulso no
     * relatório — janela rolante não pode reescrever o passado).
     */
    private function persistirClusters(array $clusters, $byId, int $hours): void
    {
        $idsJanela = $byId->keys()->all();
        $antigos = DB::table('jr_link_extracao')->whereIn('id', $idsJanela)
            ->whereNotNull('cluster_id')->distinct()->pluck('cluster_id');
        if ($antigos->isNotEmpty()) {
            // Só clusters SEM membro fora da janela podem ser apagados — os
            // demais permanecem como atribuição congelada dos itens antigos.
            $protegidos = DB::table('jr_link_extracao')->whereNotIn('id', $idsJanela)
                ->whereIn('cluster_id', $antigos)->distinct()->pluck('cluster_id');
            $apagar = $antigos->diff($protegidos);
            if ($apagar->isNotEmpty()) {
                DB::table('news_clusters')->whereIn('id', $apagar)->delete(); // cascade limpa news_cluster_items
            }
        }
        DB::table('jr_link_extracao')->whereIn('id', $idsJanela)
            ->update(['cluster_id' => null, 'cluster_rep' => false]);

        // Mapa url_norm -> news_item_id pra ligar o cluster aos news_items (feed).
        $clf = new PautaClassifier();
        $urlNorms = collect($clusters)->flatMap(fn ($c) => collect($c['ids'])->map(fn ($id) => $byId[$id]->url_norm))->filter()->unique();
        $newsItems = DB::table('news_items')
            ->where('created_at', '>=', Carbon::now()->subHours($hours * 2))
            ->get(['id', 'url']);
        $mapa = [];
        foreach ($newsItems as $n) {
            $mapa[$clf->normalizeUrl((string) $n->url)] = (int) $n->id;
        }

        foreach ($clusters as $c) {
            $rep = $byId[$c['rep']];
            $clusterId = DB::table('news_clusters')->insertGetId([
                'title' => mb_substr((string) $rep->titulo, 0, 250),
                'representative_item_id' => $mapa[$rep->url_norm] ?? null,
                'items_count' => count($c['ids']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($c['ids'] as $id) {
                $r = $byId[$id];
                DB::table('jr_link_extracao')->where('id', $id)->update([
                    'cluster_id' => $clusterId,
                    'cluster_rep' => $id === $c['rep'],
                ]);
                if (isset($mapa[$r->url_norm])) {
                    DB::table('news_cluster_items')->insertOrIgnore([
                        'news_cluster_id' => $clusterId,
                        'news_item_id' => $mapa[$r->url_norm],
                        'similarity_score' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /** Aplica âncora GA4 + regras de temperatura e grava o veredito. */
    private function aplicarVeredito(object $r, array $v, JuizLlm $juiz, string $promptVersao, array $cfg): void
    {
        $path = (string) parse_url((string) $r->url, PHP_URL_PATH);
        $tema = TituloFeatures::extract((string) $r->titulo, $path)['tema'];

        $ajusteTema = (int) ($cfg['ajuste_tema'][$tema] ?? 0);
        $ajusteGancho = (int) ($cfg['ajuste_gancho'][$v['tipo_gancho']] ?? 0);
        $scoreFinal = max(0, min(100, $v['score_llm'] + $ajusteTema + $ajusteGancho));

        if (! $v['eh_pauta'] || $v['escopo'] === 'nacional') {
            $temperatura = 'frio';
        } elseif (in_array($v['tipo_gancho'], $cfg['ganchos_fila_humana'], true)) {
            $temperatura = 'fila_humana';
        } else {
            $temperatura = $scoreFinal >= (int) $cfg['corte_quente_final'] ? 'quente' : 'frio';
        }

        DB::table('jr_link_extracao')->where('id', $r->id)->update([
            'escopo' => $v['escopo'],
            'eh_pauta' => $v['eh_pauta'],
            'tipo_gancho' => $v['tipo_gancho'],
            'cidade_llm' => $v['cidade'],
            'score_llm' => $v['score_llm'],
            'score_editorial' => $scoreFinal,
            'tema_ga4' => $tema,
            'temperatura_juiz' => $temperatura,
            'juiz_motivo' => $v['motivo'],
            'juiz_modelo' => $juiz->modelo(),
            'juiz_prompt_versao' => $promptVersao,
            'juiz_julgado_em' => now(),
        ]);
    }

    private function tabelaTemperaturas(): void
    {
        $g = DB::table('jr_link_extracao')
            ->selectRaw("coalesce(temperatura_juiz, '(sem juiz)') t, count(*) c")
            ->where('cluster_rep', true)
            ->groupBy('t')->orderByDesc('c')->get();
        foreach ($g as $x) {
            $this->line(sprintf('  %-12s %d', $x->t, $x->c));
        }
    }
}
