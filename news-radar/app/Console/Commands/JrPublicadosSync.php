<?php

namespace App\Console\Commands;

use App\Services\Jr\EventClusterer;
use App\Services\Jr\JuizLlm;
use App\Services\Jr\PautaClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * v4.2 — filtro "já publicado": espelha os posts publicados do WordPress
 * (WPGraphQL, SÓ leitura) em jr_publicado e casa contra os eventos quentes
 * do Radar. Estratégia "pré-filtro largo + LLM decide":
 *
 *   1. PRÉ-FILTRO (gerador de candidatos, generoso): overlap idf título×título
 *      reusando os tokens do EventClusterer, enriquecido com as palavras do
 *      SLUG do post (que carregam cidade/ângulo que o título às vezes omite).
 *      Top-K posts por evento acima de um overlap mínimo BAIXO — o objetivo é
 *      NÃO perder candidato, nem decidir nada aqui.
 *   2. DECISÃO (sempre do LLM, Opus): lote evento×post com prompt próprio
 *      "MESMO FATO? as manchetes podem ser totalmente diferentes — compare o
 *      fato/pessoas/lugar, não as palavras. sim/não". Cap por ciclo
 *      (publicados.llm_cap_pares), logado em jr_juiz_log como publicado_match.
 *
 * Isso casa "mesmo fato, manchete diferente" (ex.: criança de 2 anos internada
 * por maus-tratos noticiada por dois portais sem palavra em comum no título)
 * que a similaridade textual sozinha perdia.
 *
 * Match => cluster inteiro ganha ja_publicado_em + ja_publicado_slug: some do
 * Radar por default e o RadarNotificador nunca notifica. O mesmo confronto
 * roda contra jr_ig_corpus (nosso Instagram) => ja_ig_em/ja_ig_shortcode
 * (badge "✅ no IG" — informativo, não esconde).
 *
 * O prompt do juiz (JuizLlm::montarPrompt) NÃO é tocado.
 */
class JrPublicadosSync extends Command
{

    protected $signature = 'jrlink:publicados-sync '
        . '{--hours= : Janela de posts do WP (default config publicados.janela_horas)} '
        . '{--backfill : Backfill: 7 dias de WP contra o estoque quente} '
        . '{--dry : Mostra matches sem gravar nada}';

    protected $description = 'Sincroniza posts publicados (WPGraphQL -> jr_publicado) e marca eventos do Radar já publicados no site/IG.';

    public function handle(): int
    {
        $cfg = config('jrlink.publicados');
        $hours = $this->option('backfill') ? 168 : (int) ($this->option('hours') ?: $cfg['janela_horas']);
        $dry = (bool) $this->option('dry');

        // ── 1. WPGraphQL → jr_publicado (upsert por slug) ──
        $posts = $this->buscarWp((string) $cfg['endpoint'], $hours);
        $this->info(sprintf('WPGraphQL: %d posts publicados nas últimas %dh.', count($posts), $hours));
        if (! $dry && $posts) {
            foreach (array_chunk($posts, 100) as $chunk) {
                DB::table('jr_publicado')->upsert($chunk, ['slug'], ['titulo', 'categoria', 'publicado_em', 'updated_at']);
            }
        }

        // ── 2. eventos quentes ainda não marcados ──
        $cutEventos = Carbon::now()->subHours((int) $cfg['janela_eventos_horas']);
        $eventos = DB::table('jr_link_extracao')
            ->where('duplicada', false)
            ->where(function ($w) {
                $w->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->whereRaw("coalesce(temperatura_juiz, temperatura) = 'quente'")
            ->where(function ($w) use ($cutEventos) {
                $w->where('data_pub', '>=', $cutEventos->toDateString())
                    ->orWhere('created_at', '>=', $cutEventos);
            })
            ->get(['id', 'titulo', 'eixo', 'score', 'data_pub', 'created_at',
                'cluster_id', 'ja_publicado_em', 'ja_ig_em', 'markdown']);

        $capLlm = (int) $cfg['llm_cap_pares'];
        $juiz = new JuizLlm();

        // ── 3. confronto WP × eventos (usa o lote recém-buscado — funciona
        // também no --dry, que não grava em jr_publicado) ──
        $matchesWp = $this->casar(
            $eventos->whereNull('ja_publicado_em')->values(),
            collect($posts)->map(fn ($p) => (object) [
                'chave' => $p['slug'], 'titulo' => $p['titulo'],
                'quando' => $p['publicado_em'], 'extra' => $p['slug'],
            ]),
            $juiz, $capLlm, 'site'
        );

        // ── 4. confronto IG × eventos (legenda = 1ª linha como título) ──
        $alvosIg = DB::table('jr_ig_corpus')
            ->where('postado_em', '>=', Carbon::now()->subHours(max($hours, 168)))
            ->get(['shortcode', 'legenda', 'postado_em'])
            ->map(function ($p) {
                $linha = trim(strtok((string) $p->legenda, "\n") ?: '');

                return (object) ['chave' => $p->shortcode, 'titulo' => mb_substr($linha, 0, 160),
                    'quando' => $p->postado_em, 'extra' => $p->shortcode];
            })
            ->filter(fn ($p) => mb_strlen($p->titulo) >= 20)->values();
        $matchesIg = $this->casar($eventos->whereNull('ja_ig_em')->values(), $alvosIg, $juiz, $capLlm, 'instagram');

        // ── 5. efeitos ──
        $nWp = $this->marcar($matchesWp, 'ja_publicado_em', 'ja_publicado_slug', $dry, '✅ site');
        $nIg = $this->marcar($matchesIg, 'ja_ig_em', 'ja_ig_shortcode', $dry, '✅ IG');

        $this->info(sprintf('%s%d evento(s) casados com o site · %d com o Instagram.',
            $dry ? '[DRY] ' : '', $nWp, $nIg));

        return self::SUCCESS;
    }

    /** Posts publicados via WPGraphQL (paginado, cap 5 páginas). Só query. */
    private function buscarWp(string $endpoint, int $hours): array
    {
        $corte = Carbon::now()->subHours($hours);
        $gql = <<<'GQL'
        query($after: String, $y: Int, $m: Int, $d: Int) {
          posts(first: 100, after: $after, where: {status: PUBLISH, orderby: {field: DATE, order: DESC},
                 dateQuery: {after: {year: $y, month: $m, day: $d}}}) {
            pageInfo { hasNextPage endCursor }
            nodes { title slug date categories(first: 1) { nodes { name } } }
          }
        }
        GQL;

        $out = [];
        $after = null;
        for ($pagina = 0; $pagina < 5; $pagina++) {
            $r = Http::timeout(30)->post($endpoint, [
                'query' => $gql,
                // dateQuery é por DIA — recua 1 dia e refina por hora no PHP.
                'variables' => ['after' => $after, 'y' => (int) $corte->copy()->subDay()->format('Y'),
                    'm' => (int) $corte->copy()->subDay()->format('n'), 'd' => (int) $corte->copy()->subDay()->format('j')],
            ]);
            if (! $r->successful() || ! is_array($r->json('data.posts.nodes'))) {
                Log::warning('[PublicadosSync] WPGraphQL falhou: HTTP ' . $r->status() . ' ' . mb_substr($r->body(), 0, 200));
                break;
            }
            foreach ($r->json('data.posts.nodes') as $n) {
                $quando = Carbon::parse((string) $n['date']);
                if ($quando->lt($corte)) {
                    continue;
                }
                $out[] = [
                    'slug' => (string) $n['slug'],
                    'titulo' => html_entity_decode((string) $n['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'categoria' => $n['categories']['nodes'][0]['name'] ?? null,
                    'publicado_em' => $quando->format('Y-m-d H:i:s'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if (! $r->json('data.posts.pageInfo.hasNextPage')) {
                break;
            }
            $after = (string) $r->json('data.posts.pageInfo.endCursor');
        }

        return $out;
    }

    /**
     * Casa eventos × alvos (posts WP ou IG). PRÉ-FILTRO largo gera candidatos
     * (top-K posts por evento por overlap idf título×título + slug); a DECISÃO
     * de cada candidato é SEMPRE do LLM (Opus) em lote.
     *
     * @return array<int,object> evento_id => alvo (com quando/extra/titulo)
     */
    private function casar($eventos, $alvos, JuizLlm $juiz, int $capLlm, string $rotulo): array
    {
        if ($eventos->isEmpty() || $alvos->isEmpty()) {
            return [];
        }

        $cfg = config('jrlink.publicados');
        $overlapMin = (float) ($cfg['prefiltro_overlap_min'] ?? 0.12);
        $maxCand = (int) ($cfg['prefiltro_max_cand_por_evento'] ?? 6);
        $clusterer = new EventClusterer();

        // Tokens: eventos pelo título; alvos pelo título + palavras do SLUG
        // (carregam cidade/ângulo que o título às vezes omite). DF/IDF sobre o
        // corpus combinado pra ponderar entidade rara > vocabulário comum.
        $evTokens = [];
        foreach ($eventos as $i => $e) {
            $evTokens[$i] = $clusterer->tokens((string) $e->titulo);
        }
        $alTokens = [];
        $alExtraTxt = [];
        foreach ($alvos as $j => $a) {
            $slugTxt = $rotulo === 'site' ? ' ' . str_replace('-', ' ', (string) $a->extra) : '';
            $alTokens[$j] = $clusterer->tokens(((string) $a->titulo) . $slugTxt);
            $alExtraTxt[$j] = trim(str_replace('-', ' ', $rotulo === 'site' ? (string) $a->extra : ''));
        }

        $df = [];
        $docs = 0;
        foreach ([$evTokens, $alTokens] as $conj) {
            foreach ($conj as $tks) {
                $docs++;
                foreach (array_keys($tks) as $t) {
                    $df[$t] = ($df[$t] ?? 0) + 1;
                }
            }
        }
        $idf = [];
        foreach ($df as $t => $d) {
            $idf[$t] = log(1 + $docs / $d);
        }

        // Índice invertido nos tokens dos alvos (só compara pares que partilham token).
        $inv = [];
        foreach ($alTokens as $j => $tks) {
            foreach (array_keys($tks) as $t) {
                $inv[$t][] = $j;
            }
        }

        // Candidatos generosos: top-K alvos por evento acima do overlap mínimo.
        $pares = [];
        foreach ($eventos as $i => $e) {
            $somaE = 0.0;
            foreach (array_keys($evTokens[$i]) as $t) {
                $somaE += $idf[$t] ?? 0;
            }
            if ($somaE <= 0) {
                continue;
            }
            $cand = [];
            $vistosJ = [];
            foreach (array_keys($evTokens[$i]) as $t) {
                foreach ($inv[$t] ?? [] as $j) {
                    if (isset($vistosJ[$j])) {
                        continue;
                    }
                    $vistosJ[$j] = true;
                    $shared = 0.0;
                    $somaA = 0.0;
                    foreach (array_keys($alTokens[$j]) as $ta) {
                        $somaA += $idf[$ta] ?? 0;
                    }
                    foreach (array_keys($evTokens[$i]) as $te) {
                        if (isset($alTokens[$j][$te])) {
                            $shared += $idf[$te] ?? 0;
                        }
                    }
                    $ov = ($somaA > 0) ? $shared / min($somaE, $somaA) : 0.0;
                    if ($ov >= $overlapMin) {
                        $cand[$j] = $ov;
                    }
                }
            }
            arsort($cand);
            foreach (array_slice(array_keys($cand), 0, $maxCand, true) as $j) {
                $pares[] = ['ei' => $i, 'aj' => $j, 'overlap' => round($cand[$j], 3)];
            }
        }

        // Cache de "não" (7d) evita re-perguntar o mesmo par a cada ciclo de 30min.
        $pares = array_values(array_filter($pares, function ($p) use ($eventos, $alvos) {
            return Cache::get($this->chavePar((int) $eventos[$p['ei']]->id, $alvos[$p['aj']])) !== false;
        }));

        // Ordena por overlap desc e aplica o cap por ciclo (os mais promissores primeiro).
        usort($pares, fn ($a, $b) => $b['overlap'] <=> $a['overlap']);
        if ($capLlm > 0 && count($pares) > $capLlm) {
            $pares = array_slice($pares, 0, $capLlm);
        }

        $matches = [];
        if ($pares && $capLlm > 0) {
            $itens = [];
            foreach ($pares as $n => $p) {
                $e = $eventos[$p['ei']];
                $a = $alvos[$p['aj']];
                $itens[$n] = [
                    'evento' => (string) $e->titulo,
                    'evento_lead' => $this->lead($e),
                    'alvo' => (string) $a->titulo . ($alExtraTxt[$p['aj']] !== '' ? ' (' . $alExtraTxt[$p['aj']] . ')' : ''),
                ];
            }
            $vereditos = $this->julgarMesmoFato($juiz, $itens, $rotulo);
            foreach ($pares as $n => $p) {
                $eid = (int) $eventos[$p['ei']]->id;
                $alvo = $alvos[$p['aj']];
                if ($vereditos[$n] ?? false) {
                    if (! isset($matches[$eid])) {
                        $matches[$eid] = $alvo;
                    }
                    Log::info(sprintf('[PublicadosSync] LLM confirmou MESMO FATO (%s): "%s" = "%s"',
                        $rotulo, mb_strimwidth((string) $eventos[$p['ei']]->titulo, 0, 70), mb_strimwidth((string) $alvo->titulo, 0, 70)));
                } elseif (array_key_exists($n, $vereditos)) {
                    Cache::put($this->chavePar($eid, $alvo), false, now()->addDays(7));
                }
            }
        }

        return $matches;
    }

    /** Lead curto do evento (1ª frase real do markdown) pra dar contexto ao LLM. */
    private function lead(object $e): string
    {
        if (empty($e->markdown)) {
            return '';
        }
        $corpo = (new PautaClassifier())->corpoFromMarkdown((string) $e->markdown);

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $corpo)), 0, 240);
    }

    private function chavePar(int $eventoId, object $alvo): string
    {
        return 'jrpub:nao:' . $eventoId . ':' . md5((string) $alvo->chave);
    }

    /**
     * Lote LLM "MESMO FATO? sim/não" — prompt PRÓPRIO deste comando (o prompt do
     * juiz não muda). A decisão compara fato/pessoas/lugar, NÃO as palavras das
     * manchetes. Roda no modelo da função match_publicado (Opus). Logado via
     * completarJson como publicado_match.
     *
     * @param  array<int,array{evento:string,evento_lead:string,alvo:string}>  $itens
     * @return array<int,bool> por índice
     */
    private function julgarMesmoFato(JuizLlm $juiz, array $itens, string $rotulo): array
    {
        // Lotes de 20 pares: prompt focado preserva a precisão do Opus (lista
        // longa demais degrada). Cada lote = 1 chamada/1 log publicado_match.
        $out = [];
        foreach (array_chunk($itens, 20, true) as $chunk) {
            $out += $this->julgarLoteMesmoFato($juiz, $chunk, $rotulo);
        }

        return $out;
    }

    /** @param  array<int,array{evento:string,evento_lead:string,alvo:string}>  $itens */
    private function julgarLoteMesmoFato(JuizLlm $juiz, array $itens, string $rotulo): array
    {
        $fonte = $rotulo === 'site' ? 'JÁ PUBLICADO NO SITE' : 'JÁ PUBLICADO NO INSTAGRAM';
        $lista = '';
        foreach ($itens as $n => $it) {
            $lead = $it['evento_lead'] !== '' ? "\nCONTEXTO DO RADAR: {$it['evento_lead']}" : '';
            $lista .= sprintf("PAR %d\nPAUTA DO RADAR: %s%s\n%s: %s\n\n",
                $n, trim($it['evento']), $lead, $fonte, trim($it['alvo']));
        }
        $prompt = <<<PROMPT
        Você é o editor do Jornal Razão conferindo se uma pauta do radar é a MESMA notícia que o jornal JÁ PUBLICOU. Para cada par abaixo, responda se o evento do radar é o MESMO FATO que o post já publicado.

        IMPORTANTE: as manchetes podem ser TOTALMENTE DIFERENTES, com nenhuma palavra em comum — compare o FATO concreto (o que aconteceu), as PESSOAS, o LUGAR e o momento, NÃO as palavras. Ex.: "Criança de 2 anos internada em UTI após suspeita de maus-tratos em SC" e "Criança de 2 anos é internada com lesões: caiu no banho, diz mãe" são o MESMO FATO (mesma criança, mesma internação, mesmo lugar) mesmo sem palavras iguais.

        Responda MESMO=true só quando for o mesmo acontecimento concreto. Fato parecido em outra cidade/dia, outra vítima, ou desdobramento NOVO com fato novo = false. Tema genérico em comum (ex.: "dois acidentes diferentes") = false.

        RESPONDA APENAS com um array JSON, sem texto fora dele:
        [{"par": <n>, "mesmo": true|false}]

        PARES:

        {$lista}
        PROMPT;

        $arr = $juiz->completarJson($prompt, 'publicado_match', count($itens), $juiz->modeloFuncao('match_publicado'));
        $out = [];
        foreach ($arr as $v) {
            if (isset($v['par'])) {
                $out[(int) $v['par']] = (bool) ($v['mesmo'] ?? false);
            }
        }

        return $out;
    }

    /** Marca o CLUSTER inteiro do evento casado (a história toda some/ganha badge). */
    private function marcar(array $matches, string $colQuando, string $colRef, bool $dry, string $rotulo): int
    {
        if (! $matches) {
            return 0;
        }
        $eventos = DB::table('jr_link_extracao')->whereIn('id', array_keys($matches))
            ->get(['id', 'titulo', 'cluster_id'])->keyBy('id');

        $n = 0;
        foreach ($matches as $eid => $alvo) {
            $e = $eventos[$eid] ?? null;
            if (! $e) {
                continue;
            }
            $this->line(sprintf('  %s %s"%s" ← "%s" (%s)', $rotulo, $dry ? '[DRY] ' : '',
                mb_strimwidth((string) $e->titulo, 0, 60), mb_strimwidth((string) $alvo->titulo, 0, 60), $alvo->extra));
            if (! $dry) {
                $q = $e->cluster_id
                    ? DB::table('jr_link_extracao')->where('cluster_id', $e->cluster_id)
                    : DB::table('jr_link_extracao')->where('id', $e->id);
                $q->whereNull($colQuando)->update([
                    $colQuando => $alvo->quando,
                    $colRef => $alvo->extra,
                ]);
            }
            $n++;
        }

        return $n;
    }
}
