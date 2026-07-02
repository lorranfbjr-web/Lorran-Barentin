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
        . '{--ig-backlog : Julga o BACKLOG de posts de Instagram não-julgados direto (sem re-clusterizar a janela)} '
        . '{--force : Re-julga mesmo quem já tem veredito desta prompt_versao}';

    protected $description = 'Camadas 2+3 da Fase 2: colapsa eventos via news_clusters e julga os representantes com o juiz LLM (título+lead, JSON estrito).';

    public function handle(): int
    {
        $cfg = config('jrlink.juiz');
        $hours = (int) $this->option('hours');
        $promptVersao = (string) $cfg['prompt_versao'];

        // Via dedicada: limpa o backlog de IG (itens > janela, que o clustering
        // pesado de 168h não alcança) julgando-os direto pelos clusters que já têm.
        if ($this->option('ig-backlog')) {
            return $this->julgarIgBacklog($cfg, $promptVersao);
        }

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

        // ── CANAIS SOCIAIS (Instagram + WhatsApp) direto ao juiz ──
        // O gate coarse foi calibrado pra MANCHETE de portal; o scraper de IG
        // salva a 1ª linha da LEGENDA como "título" (emoji/horário/frase solta)
        // e o release de WhatsApp é texto de grupo sem cara de manchete — ambos
        // quase nunca passam o coarse e morreriam antes do juiz. Aqui todo item
        // origem=instagram OU origem=whatsapp (não-julgado) entra DIRETO, sem
        // exigir score coarse. FEED segue só pela via de cluster-rep acima —
        // esta via NÃO o toca. O juiz decide pauta lendo o texto (aviso no
        // input). Dedup do display segue por cluster (radar mostra 1 por evento).
        $igDireto = 0;
        $waDireto = 0;
        if (! $this->option('ids')) {
            $jaNaLista = collect($aJulgar)->keyBy('id');
            foreach ($rows as $r) {
                if (in_array($r->origem, ['instagram', 'whatsapp'], true) && ! $jaNaLista->has($r->id)) {
                    $aJulgar[] = $r;
                    $r->origem === 'whatsapp' ? $waDireto++ : $igDireto++;
                }
            }
        }
        if ($igDireto > 0) {
            $this->info(sprintf('Canal Instagram: +%d posts de IG roteados direto ao juiz (sem gate coarse).', $igDireto));
        }
        if ($waDireto > 0) {
            $this->info(sprintf('Canal WhatsApp: +%d release(s) de grupo roteados direto ao juiz (sem gate coarse).', $waDireto));
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

        $juiz = new JuizLlm(null, $this->option('driver') ?: null);
        $this->info(sprintf('Driver: %s · modelo: %s', $juiz->driver(), $juiz->modelo()));

        // ── few-shot (calibração do editor): prova explícita de que entra no input ──
        $fs = $juiz->fewShotInfo();
        $msgFs = $fs['enabled']
            ? sprintf('Few-shot: LIGADO — %d exemplos do feedback injetados no input do juiz.', $fs['exemplos'])
            : 'Few-shot: desligado (JRLINK_JUIZ_FEWSHOT).';
        $this->info($msgFs);
        \Illuminate\Support\Facades\Log::info('[JrLinkJuiz] ' . $msgFs);

        // ── merge assistido por LLM: pares limítrofes que a similaridade não decide ──
        $fundidos = $this->mergeLlmClusters($clusterer, $byId, $juiz);
        if ($fundidos > 0) {
            $this->info("Merge LLM: {$fundidos} cluster(s) fundidos (títulos reformulados).");
        }

        // ── camada 3: juiz LLM nos pendentes ──
        ['julgados' => $julgados, 'falhas' => $falhas, 'velho' => $velhoPorId] =
            $this->processarLotes($pendentes, $juiz, $promptVersao, $cfg, $lote);

        // ── resumo ──
        $custo = DB::table('jr_juiz_log')->where('created_at', '>=', Carbon::now()->subMinutes(30))->sum('custo_usd');
        $this->newLine();
        $this->info(sprintf('Juiz concluído: %d julgados · %d lotes com falha · custo (últimos 30min): US$ %.4f',
            $julgados, $falhas, (float) $custo));
        if (count($velhoPorId) > 0) {
            $this->info(sprintf('Guarda fato-velho: %d item(ns) sinalizados (alerta no input + rebaixados a frio se vinham quentes).', count($velhoPorId)));
        }
        $this->tabelaTemperaturas();

        // ── notificação (digest de quentes novos pro Raspador; kill switch por env) ──
        $notif = (new \App\Services\Jr\RadarNotificador())->notificarNovos();
        $this->line(sprintf('Notificação: %s (novos=%d, na mensagem=%d%s)',
            $notif['status'], $notif['novos'], $notif['enviados'],
            $notif['message_id'] ? ', messageId=' . $notif['message_id'] : ''));

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
            // RECENTES PRIMEIRO: notícia fresca é julgada antes do backlog velho,
            // então aparece no radar sem esperar a fila. Guard contra data_pub
            // FUTURA (feed com data errada): se data_pub > agora, usa created_at —
            // senão uma data bugada ficaria eternamente no topo.
            ->orderByRaw("(case when data_pub is not null and julianday(data_pub) <= julianday('now')
                then julianday(data_pub) else julianday(created_at) end) desc")
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

    /**
     * Merge assistido por LLM: pega os pares LIMÍTROFES do clustering (similaridade
     * intermediária, vetos de cidade/idade já aplicados), pergunta em LOTE "mesmo
     * evento?" (prompt próprio — o do juiz não muda) e funde clusters no sim.
     * Cap por ciclo em jrlink.cluster.llm_merge_pares. Fusões logadas.
     */
    private function mergeLlmClusters(EventClusterer $clusterer, $byId, JuizLlm $juiz): int
    {
        $cap = (int) config('jrlink.cluster.llm_merge_pares', 40);
        $limitrofes = $clusterer->paresLimitrofes();
        if ($cap <= 0 || ! $limitrofes) {
            return 0;
        }

        $ids = collect($limitrofes)->flatMap(fn ($p) => [$p['id_a'], $p['id_b']])->unique();
        $clusterDe = DB::table('jr_link_extracao')->whereIn('id', $ids)->pluck('cluster_id', 'id');

        $vistos = [];
        $paresCluster = [];
        foreach ($limitrofes as $p) {
            $ca = $clusterDe[$p['id_a']] ?? null;
            $cb = $clusterDe[$p['id_b']] ?? null;
            if (! $ca || ! $cb || $ca === $cb) {
                continue;
            }
            $key = min($ca, $cb) . ':' . max($ca, $cb);
            if (isset($vistos[$key])) {
                continue;
            }
            $vistos[$key] = true;
            $paresCluster[] = ['ca' => (int) $ca, 'cb' => (int) $cb,
                'a' => (string) $byId[$p['id_a']]->titulo, 'b' => (string) $byId[$p['id_b']]->titulo];
            if (count($paresCluster) >= $cap) {
                break;
            }
        }
        if (! $paresCluster) {
            return 0;
        }

        $veredito = $juiz->julgarMesmoEvento(array_map(fn ($p) => ['a' => $p['a'], 'b' => $p['b']], $paresCluster));

        $remap = [];
        $fundidos = 0;
        foreach ($paresCluster as $n => $p) {
            if (! ($veredito[$n] ?? false)) {
                continue;
            }
            $ca = $remap[$p['ca']] ?? $p['ca'];
            $cb = $remap[$p['cb']] ?? $p['cb'];
            if ($ca === $cb) {
                continue;
            }
            // Alvo: cluster cujo representante já foi julgado; senão o maior.
            $reps = DB::table('jr_link_extracao')->whereIn('cluster_id', [$ca, $cb])
                ->where('cluster_rep', true)->get(['cluster_id', 'juiz_julgado_em']);
            $contagem = DB::table('jr_link_extracao')->whereIn('cluster_id', [$ca, $cb])
                ->selectRaw('cluster_id, count(*) n')->groupBy('cluster_id')->pluck('n', 'cluster_id');
            $julgadoA = (bool) $reps->firstWhere('cluster_id', $ca)?->juiz_julgado_em;
            $julgadoB = (bool) $reps->firstWhere('cluster_id', $cb)?->juiz_julgado_em;
            [$alvo, $perde] = $julgadoA === $julgadoB
                ? ((($contagem[$ca] ?? 0) >= ($contagem[$cb] ?? 0)) ? [$ca, $cb] : [$cb, $ca])
                : ($julgadoA ? [$ca, $cb] : [$cb, $ca]);

            DB::table('jr_link_extracao')->where('cluster_id', $perde)
                ->update(['cluster_id' => $alvo, 'cluster_rep' => false]);
            DB::table('news_clusters')->where('id', $alvo)
                ->update(['items_count' => (int) ($contagem[$ca] ?? 0) + (int) ($contagem[$cb] ?? 0)]);
            DB::table('news_clusters')->where('id', $perde)->delete();
            \Illuminate\Support\Facades\Log::info(sprintf(
                '[ClusterMergeLLM] fundiu cluster %d -> %d: "%s" ≈ "%s"',
                $perde, $alvo, mb_strimwidth($p['a'], 0, 70), mb_strimwidth($p['b'], 0, 70)));
            $remap[$perde] = $alvo;
            $fundidos++;
        }

        return $fundidos;
    }

    /**
     * Julga $pendentes em lotes: monta o input (aviso de legenda p/ IG + alerta
     * de fato-velho), chama o juiz e aplica o veredito. Compartilhado entre o
     * ciclo normal e o backlog de IG.
     *
     * @return array{julgados:int, falhas:int, velho:array<int,string>}
     */
    private function processarLotes(array $pendentes, JuizLlm $juiz, string $promptVersao, array $cfg, int $lote): array
    {
        $clf = new PautaClassifier();
        $fatoVelho = new \App\Services\Jr\FatoVelho();
        $julgados = 0;
        $falhas = 0;
        $velhoPorId = [];
        foreach (array_chunk($pendentes, $lote) as $chunk) {
            // COBERTURA por item: nº de hosts distintos no cluster (a régua do
            // prompt cita "cobertura múltipla independente de portais" — este é
            // o campo que a torna verificável em vez de adivinhada).
            $clusterIds = array_values(array_filter(array_unique(array_map(fn ($r) => $r->cluster_id ?? null, $chunk))));
            $cobertura = $clusterIds === [] ? collect() : DB::table('jr_link_extracao')
                ->whereIn('cluster_id', $clusterIds)
                ->selectRaw('cluster_id, count(distinct host) n')
                ->groupBy('cluster_id')->pluck('n', 'cluster_id');

            $itens = array_map(function ($r) use ($clf, $fatoVelho, $cobertura, &$velhoPorId) {
                // CANAL INSTAGRAM/WHATSAPP: o "título" é legenda/mensagem sem cara
                // de manchete. O aviso vai no campo CONTEXTO do item (fora do
                // truncamento do lead) — o prompt BASE do juiz não muda.
                $contexto = match ($r->origem ?? null) {
                    'instagram' => 'Este texto é a LEGENDA de um post de INSTAGRAM de um perfil regional — pode começar com emoji, horário ou frase solta, sem formato de manchete de jornal. Avalie o ASSUNTO da legenda como possível pauta, não o formato.',
                    'whatsapp' => 'Este texto é uma MENSAGEM de um grupo de WhatsApp de imprensa/release (assessoria de prefeitura, polícia, órgão público ou veículo) — pode ser informal, sem formato de manchete de jornal. Avalie o ASSUNTO da mensagem como possível pauta, não o formato.',
                    default => null,
                };

                // GUARDA DE FATO-VELHO: a instrução vai no campo ALERTA (fora do
                // truncamento — antes era anexada ao fim do lead e o corte de
                // 280 chars a engolia). Heurística sinaliza pro LLM raciocinar E
                // rebaixa de forma determinística no aplicarVeredito (o corpo às
                // vezes afirma "lei nova" e o LLM não tem como datar a sanção —
                // a heurística é a autoridade aqui).
                $alerta = null;
                $sinal = $fatoVelho->analisar((string) $r->titulo, (string) $r->markdown, $r->data_pub ?: $r->created_at);
                if ($sinal !== null) {
                    $velhoPorId[(int) $r->id] = $sinal;
                    $alerta = "possível FATO ANTIGO apenas re-noticiado: {$sinal}. "
                        . 'Se o acontecimento central NÃO for recente (lei já em vigor há tempo, efeméride, '
                        . 'retrospectiva, recapitulação de fato anterior), marque eh_pauta=false mesmo que a '
                        . 'data de publicação seja recente.';
                }

                // PUBLICADO: data confiável + idade (guard contra data_pub futura,
                // mesma lógica do candidatos()).
                $publicado = null;
                try {
                    $dt = Carbon::parse($r->data_pub ?: $r->created_at);
                    if ($dt->isFuture()) {
                        $dt = Carbon::parse($r->created_at);
                    }
                    $horas = (int) $dt->diffInHours(Carbon::now());
                    $publicado = $dt->format('d/m/Y H:i') . ' (há ' . ($horas < 48 ? $horas . 'h' : intdiv($horas, 24) . ' dias') . ')';
                } catch (\Throwable) {
                    // data ilegível — item segue sem o metadado
                }

                return [
                    'id' => (int) $r->id,
                    'titulo' => (string) $r->titulo,
                    'lead' => $clf->corpoFromMarkdown($r->markdown),
                    'fonte' => (string) ($r->host ?? ''),
                    'origem' => (string) ($r->origem ?? 'feed'),
                    'publicado' => $publicado,
                    'portais' => ($r->cluster_id ?? null) ? (int) ($cobertura[$r->cluster_id] ?? 1) : 1,
                    'contexto' => $contexto,
                    'alerta' => $alerta,
                ];
            }, $chunk);

            try {
                $vereditos = $juiz->julgarLote($itens);
            } catch (\Throwable $e) {
                $falhas++;
                $this->error('Lote falhou: ' . $e->getMessage());
                continue;
            }

            foreach ($chunk as $r) {
                // B4 (02/07): veredito parcial — item omitido pelo modelo fica
                // não-julgado e volta na fila do próximo ciclo.
                if (! isset($vereditos[(int) $r->id])) {
                    continue;
                }
                $this->aplicarVeredito($r, $vereditos[(int) $r->id], $juiz, $promptVersao, $cfg, $velhoPorId[(int) $r->id] ?? null);
                $julgados++;
            }
            $this->line(sprintf('  julgados %d/%d…', $julgados, count($pendentes)));
        }

        return ['julgados' => $julgados, 'falhas' => $falhas, 'velho' => $velhoPorId];
    }

    /**
     * Backlog do CANAL INSTAGRAM: julga TODOS os posts de IG ainda não julgados
     * com esta prompt_versao, direto, sem re-rodar o clustering pesado da janela
     * (os itens de IG já têm cluster_id de ciclos anteriores). Roda os MESMOS
     * guardas (legenda no input, fato-velho) e o notificador ao final (com o
     * match já-publicado + decaimento). Não toca feed/whatsapp.
     */
    private function julgarIgBacklog(array $cfg, string $promptVersao): int
    {
        $pendentes = DB::table('jr_link_extracao')
            ->where('origem', 'instagram')
            ->where('duplicada', false)
            ->where(function ($q) use ($promptVersao) {
                $q->whereNull('juiz_julgado_em')->orWhere('juiz_prompt_versao', '!=', $promptVersao);
            })
            ->orderBy('id')
            ->get()->all();

        $this->info(sprintf('Backlog IG: %d posts de Instagram não-julgados (prompt %s).', count($pendentes), $promptVersao));
        if (! $pendentes) {
            return self::SUCCESS;
        }

        $lote = max(1, (int) $cfg['lote']);
        $chamadas = (int) ceil(count($pendentes) / $lote);
        if ($chamadas > (int) $cfg['cap_chamadas']) {
            $this->error(sprintf('ABORTADO: %d chamadas > cap %d.', $chamadas, (int) $cfg['cap_chamadas']));

            return self::FAILURE;
        }

        $juiz = new JuizLlm(null, $this->option('driver') ?: null);
        $fs = $juiz->fewShotInfo();
        $this->info(sprintf('Driver: %s · modelo: %s · few-shot: %s', $juiz->driver(), $juiz->modelo(),
            $fs['enabled'] ? $fs['exemplos'] . ' exemplos' : 'off'));

        ['julgados' => $julgados, 'falhas' => $falhas, 'velho' => $velho] =
            $this->processarLotes($pendentes, $juiz, $promptVersao, $cfg, $lote);

        $custo = DB::table('jr_juiz_log')->where('created_at', '>=', Carbon::now()->subMinutes(30))->sum('custo_usd');
        $this->info(sprintf('Backlog IG concluído: %d julgados · %d falhas · %d fato-velho · custo 30min US$ %.4f',
            $julgados, $falhas, count($velho), (float) $custo));
        $this->tabelaTemperaturas();

        // Notificação com os MESMOS guardas (match já-publicado síncrono + idade).
        $notif = (new \App\Services\Jr\RadarNotificador())->notificarNovos();
        $this->line(sprintf('Notificação: %s (novos=%d, na mensagem=%d%s)',
            $notif['status'], $notif['novos'], $notif['enviados'],
            $notif['message_id'] ? ', messageId=' . $notif['message_id'] : ''));

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Aplica âncora GA4 + regras de temperatura e grava o veredito. */
    private function aplicarVeredito(object $r, array $v, JuizLlm $juiz, string $promptVersao, array $cfg, ?string $sinalVelho = null): void
    {
        $path = (string) parse_url((string) $r->url, PHP_URL_PATH);
        $tema = TituloFeatures::extract((string) $r->titulo, $path)['tema'];

        $ajusteTema = (int) ($cfg['ajuste_tema'][$tema] ?? 0);
        $ajusteGancho = (int) ($cfg['ajuste_gancho'][$v['tipo_gancho']] ?? 0);
        $scoreFinal = max(0, min(100, $v['score_llm'] + $ajusteTema + $ajusteGancho));

        $ehPauta = $v['eh_pauta'];
        $motivo = $v['motivo'];

        if (! $v['eh_pauta'] || $v['escopo'] === 'nacional') {
            $temperatura = 'frio';
        } elseif (in_array($v['tipo_gancho'], $cfg['ganchos_fila_humana'], true)) {
            $temperatura = 'fila_humana';
        } else {
            $temperatura = $scoreFinal >= (int) $cfg['corte_quente_final'] ? 'quente' : 'frio';
        }

        // GUARDA DE FATO-VELHO (determinística): o LLM às vezes não consegue datar
        // o fato (corpo afirma "lei nova" sem dizer quando sancionou). Se a
        // heurística sinalizou fato antigo, rebaixa pra frio independente do
        // veredito/score — o radar é de pauta NOVA.
        if ($sinalVelho !== null && $temperatura === 'quente') {
            $temperatura = 'frio';
            $ehPauta = false;
            $motivo = 'FATO ANTIGO re-noticiado (' . $sinalVelho . '). ' . $motivo;
        }

        DB::table('jr_link_extracao')->where('id', $r->id)->update([
            'escopo' => $v['escopo'],
            'eh_pauta' => $ehPauta,
            'tipo_gancho' => $v['tipo_gancho'],
            'cidade_llm' => $v['cidade'],
            'score_llm' => $v['score_llm'],
            'score_editorial' => $scoreFinal,
            'tema_ga4' => $tema,
            'temperatura_juiz' => $temperatura,
            'juiz_motivo' => mb_substr($motivo, 0, 400),
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
