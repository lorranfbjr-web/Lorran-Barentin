<?php

namespace App\Console\Commands;

use App\Services\Jr\EventClusterer;
use App\Services\Jr\JuizLlm;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * v4.1 — filtro "já publicado": espelha os posts publicados do WordPress
 * (WPGraphQL, SÓ leitura) em jr_publicado e casa contra os eventos quentes
 * do Radar. Camadas do matching, na mesma filosofia da Fase 2:
 *
 *   1. similaridade textual = a MESMA máquina do EventClusterer (overlap idf
 *      + âncora + vetos de cidade/idade): WP posts e líderes de evento entram
 *      juntos no cluster(); co-membro = match direto, zero custo;
 *   2. pares LIMÍTROFES (similar mas não fundiu) vão pro LLM em LOTE com
 *      prompt PRÓPRIO "mesma história? sim/não" (cap publicados.llm_cap_pares
 *      por ciclo, logado em jr_juiz_log como publicado_match).
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
    /** Offset dos ids-fantasma (WP/IG) dentro do cluster() — nunca colide com jr_link_extracao. */
    private const PSEUDO = 900_000_000;

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
                'cluster_id', 'ja_publicado_em', 'ja_ig_em']);

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
     * Casa eventos × alvos (posts WP ou IG) reusando o EventClusterer: alvos
     * entram como linhas-fantasma; co-membro do mesmo cluster = match direto,
     * pares limítrofes evento×alvo vão pro LLM ("mesma história? sim/não").
     *
     * @return array<int,object> evento_id => alvo (com quando/extra)
     */
    private function casar($eventos, $alvos, JuizLlm $juiz, int $capLlm, string $rotulo): array
    {
        if ($eventos->isEmpty() || $alvos->isEmpty()) {
            return [];
        }

        $porPseudo = [];
        $rows = [];
        foreach ($eventos as $e) {
            $rows[] = $e;
        }
        foreach ($alvos as $i => $a) {
            $pid = self::PSEUDO + $i;
            $porPseudo[$pid] = $a;
            $rows[] = (object) ['id' => $pid, 'titulo' => $a->titulo, 'eixo' => 'concorrente',
                'score' => 0, 'data_pub' => $a->quando, 'created_at' => $a->quando];
        }

        $clusterer = new EventClusterer();
        $clusters = $clusterer->cluster($rows);

        // Match direto: evento e alvo no MESMO cluster.
        $matches = [];
        foreach ($clusters as $c) {
            $pseudos = array_values(array_filter($c['ids'], fn ($id) => isset($porPseudo[$id])));
            $reais = array_values(array_filter($c['ids'], fn ($id) => ! isset($porPseudo[$id])));
            if ($pseudos && $reais) {
                foreach ($reais as $eid) {
                    $matches[$eid] = $porPseudo[$pseudos[0]];
                }
            }
        }

        // Duvidosos: pares limítrofes evento×alvo que a similaridade não decidiu.
        $pares = [];
        $byId = collect($rows)->keyBy('id');
        foreach ($clusterer->paresLimitrofes() as $p) {
            $aPseudo = isset($porPseudo[$p['id_a']]);
            $bPseudo = isset($porPseudo[$p['id_b']]);
            if ($aPseudo === $bPseudo) {
                continue; // evento×evento ou alvo×alvo não interessam aqui
            }
            $eid = $aPseudo ? $p['id_b'] : $p['id_a'];
            $pid = $aPseudo ? $p['id_a'] : $p['id_b'];
            if (isset($matches[$eid])) {
                continue;
            }
            // "não" do LLM é lembrado 7 dias — o ciclo de 30min não re-pergunta
            // o mesmo par toda vez (o "sim" se auto-resolve: o evento é marcado).
            if (Cache::get($this->chavePar($eid, $porPseudo[$pid])) === false) {
                continue;
            }
            $pares[] = ['eid' => $eid, 'pid' => $pid,
                'evento' => (string) $byId[$eid]->titulo, 'alvo' => (string) $byId[$pid]->titulo];
            if (count($pares) >= $capLlm) {
                break;
            }
        }

        if ($pares && $capLlm > 0) {
            $vereditos = $this->julgarMesmaHistoria($juiz, $pares, $rotulo);
            foreach ($pares as $n => $p) {
                if ($vereditos[$n] ?? false) {
                    $matches[$p['eid']] = $porPseudo[$p['pid']];
                    Log::info(sprintf('[PublicadosSync] LLM confirmou (%s): "%s" = "%s"',
                        $rotulo, mb_strimwidth($p['evento'], 0, 70), mb_strimwidth($p['alvo'], 0, 70)));
                } elseif (array_key_exists($n, $vereditos)) {
                    Cache::put($this->chavePar($p['eid'], $porPseudo[$p['pid']]), false, now()->addDays(7));
                }
            }
        }

        return $matches;
    }

    private function chavePar(int $eventoId, object $alvo): string
    {
        return 'jrpub:nao:' . $eventoId . ':' . md5((string) $alvo->chave);
    }

    /**
     * Lote LLM "mesma história? sim/não" — prompt PRÓPRIO deste comando (o
     * prompt do juiz não muda). Logado via completarJson como publicado_match.
     *
     * @return array<int,bool> por índice do par
     */
    private function julgarMesmaHistoria(JuizLlm $juiz, array $pares, string $rotulo): array
    {
        $lista = '';
        foreach ($pares as $n => $p) {
            $lista .= sprintf("PAR %d\nPAUTA DO RADAR: %s\nJÁ PUBLICADO (%s): %s\n\n",
                $n, trim($p['evento']), $rotulo, trim($p['alvo']));
        }
        $prompt = <<<PROMPT
        Você confere se o Jornal Razão JÁ PUBLICOU uma pauta. Para cada par abaixo, responda se os dois textos contam a MESMA HISTÓRIA (mesmo fato concreto, mesmas pessoas/lugar — manchete reformulada ou ângulo diferente do MESMO fato conta como mesma história; fato parecido em outra cidade/dia, desdobramento novo com fato novo, ou tema genérico em comum NÃO conta).

        RESPONDA APENAS com um array JSON, sem texto fora dele:
        [{"par": <n>, "mesma": true|false}]

        PARES:

        {$lista}
        PROMPT;

        $arr = $juiz->completarJson($prompt, 'publicado_match', count($pares));
        $out = [];
        foreach ($arr as $v) {
            if (isset($v['par'])) {
                $out[(int) $v['par']] = (bool) ($v['mesma'] ?? false);
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
