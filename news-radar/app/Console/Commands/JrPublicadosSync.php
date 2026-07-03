<?php

namespace App\Console\Commands;

use App\Services\Jr\JuizLlm;
use App\Services\Jr\PublicadoMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
        $matcher = new PublicadoMatcher();

        // ── 3. confronto eventos × jr_publicado (TABELA acumulada, janela de
        // match em DIAS — não só o lote recém-buscado: condenação/desdobramento
        // sai dias depois do fato). No --dry usa o lote recém-buscado + a tabela. ──
        $diasMatch = (int) ($cfg['janela_match_dias'] ?? 30);
        $alvosSite = DB::table('jr_publicado')
            ->where('publicado_em', '>=', Carbon::now()->subDays($diasMatch)->toDateTimeString())
            ->get(['slug', 'titulo', 'publicado_em'])
            ->map(fn ($p) => (object) ['chave' => $p->slug, 'titulo' => $p->titulo,
                'quando' => $p->publicado_em, 'extra' => $p->slug]);
        if ($dry && $posts) {
            // no dry os posts buscados podem ainda não estar na tabela — soma-os.
            $jaTem = $alvosSite->pluck('chave')->flip();
            $alvosSite = $alvosSite->concat(collect($posts)
                ->reject(fn ($p) => isset($jaTem[$p['slug']]))
                ->map(fn ($p) => (object) ['chave' => $p['slug'], 'titulo' => $p['titulo'],
                    'quando' => $p['publicado_em'], 'extra' => $p['slug']]))->values();
        }
        $this->info(sprintf('Confronto: %d eventos quentes × %d posts publicados (%d dias).',
            $eventos->whereNull('ja_publicado_em')->count(), $alvosSite->count(), $diasMatch));
        $matchesWp = $matcher->casar(
            $eventos->whereNull('ja_publicado_em')->values(), $alvosSite, $juiz, $capLlm, 'site'
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
        $matchesIg = $matcher->casar($eventos->whereNull('ja_ig_em')->values(), $alvosIg, $juiz, $capLlm, 'instagram');

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
            // BLOCO 4 (simplificar 03/07): retry 2× com backoff + timeout de
            // conexão explícito; ConnectionException não derruba mais o run
            // inteiro (era a causa nº 1 do vazamento: cURL 28 às 07:31 matou o
            // sync do dia e nada foi marcado como publicado).
            try {
                $r = Http::connectTimeout(10)->timeout(30)->retry(2, 500)->post($endpoint, [
                    'query' => $gql,
                    // dateQuery é por DIA — recua 1 dia e refina por hora no PHP.
                    'variables' => ['after' => $after, 'y' => (int) $corte->copy()->subDay()->format('Y'),
                        'm' => (int) $corte->copy()->subDay()->format('n'), 'd' => (int) $corte->copy()->subDay()->format('j')],
                ]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::warning('[PublicadosSync] WPGraphQL fora do ar (após retry): ' . $e->getMessage());
                break;
            }
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
