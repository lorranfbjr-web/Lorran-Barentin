<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API da aba Radar — v3: EVENTO como unidade. Cada card é um cluster (item sem
 * cluster = evento de 1). Por evento: item líder (maior score, desempate mais
 * recente), score = MAX dos itens, fontes distintas, primeira/última cobertura.
 * Seções: quentes (default) · alta (trending por nº de portais, sem IA) ·
 * geral (tudo, compacto) · fila (humana). Voto de feedback = no item líder.
 *
 * v4.1 "tempo real": score_atual = score do juiz × fator de decaimento pela
 * idade da publicação original (config jrlink.radar.decaimento) — ordenação
 * default "Quente agora". Eventos já publicados no site (ja_publicado_em)
 * somem por default (?publicadas=1 mostra com badge). Filtros de triagem:
 * ?nao_votados=1 e ?max_idade_h=N; meta traz votados/total.
 */
class JrRadarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $secao = $request->query('secao', 'quentes');
        $janela = min(168, max(6, (int) $request->query('janela', 48)));
        $cutoff = now()->subHours($janela);

        if ($secao === 'alta') {
            return $this->emAlta($cutoff);
        }

        $sort = $request->query('sort', $secao === 'geral' ? 'recente' : 'hot');
        $perPage = min(50, max(5, (int) $request->query('limit', 20)));

        // Representantes = 1 linha por evento (cluster_rep ou sem cluster).
        $q = DB::table('jr_link_extracao')
            ->where('duplicada', false)
            ->where(function ($w) {
                $w->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->where(function ($w) use ($cutoff) {
                $w->where('data_pub', '>=', $cutoff->toDateString())
                    ->orWhere(function ($ww) use ($cutoff) {
                        $ww->whereNull('data_pub')->where('created_at', '>=', $cutoff);
                    });
            });

        if ($secao === 'fila') {
            $q->where('temperatura_juiz', 'fila_humana');
        } elseif ($secao !== 'geral') {
            $q->whereRaw("coalesce(temperatura_juiz, temperatura) = 'quente'");
        }

        if (in_array($request->query('eixo'), ['primaria', 'concorrente'], true)) {
            $q->where('eixo', $request->query('eixo'));
        }
        if (($min = (int) $request->query('score_min', 0)) > 0) {
            $q->where('score_editorial', '>=', $min);
        }
        if (($cidade = trim((string) $request->query('cidade'))) !== '') {
            $q->where('cidade_llm', 'like', '%' . $cidade . '%');
        }
        if (($busca = trim((string) $request->query('q'))) !== '') {
            $q->where(function ($w) use ($busca) {
                $w->where('titulo', 'like', '%' . $busca . '%')
                    ->orWhere('juiz_motivo', 'like', '%' . $busca . '%');
            });
        }

        // Já publicado no site some por default; ?publicadas=1 traz com badge.
        if ($request->query('publicadas') !== '1') {
            $q->whereNull('ja_publicado_em');
        }
        // Chip "Agora": só eventos com publicação original mais nova que N horas.
        if (($maxIdade = (int) $request->query('max_idade_h', 0)) > 0) {
            $q->whereRaw(self::idadeHorasSql() . ' < ?', [$maxIdade]);
        }
        // Triagem: só eventos ainda sem voto humano (em qualquer item do cluster).
        if ($request->query('nao_votados') === '1') {
            $q->whereNotExists(fn ($w) => self::subVoto($w));
        }

        if ($sort === 'recente') {
            $q->orderByRaw('coalesce(data_pub, created_at) desc');
        } elseif ($sort === 'score') {
            $q->orderByRaw('coalesce(score_editorial, -1) desc')->orderByDesc('score');
        } else {
            // "Quente agora" (default): score do juiz × decaimento pela idade.
            // MESMA base do score_atual exibido: score = MAX do evento, idade =
            // publicação original do LÍDER (maior score coarse, desempate recente).
            $q->orderByRaw('(' . self::scoreEventoSql() . ') * (' . self::fatorSql() . ') desc')
                ->orderByDesc('score');
        }

        $page = $q->paginate($perPage, [
            'id', 'titulo', 'url', 'host', 'fonte_tipo', 'origem', 'eixo',
            'temperatura', 'temperatura_juiz', 'score', 'score_editorial',
            'escopo', 'tipo_gancho', 'cidade_llm', 'tema_ga4', 'juiz_motivo',
            'cluster_id', 'data_pub', 'created_at', 'notificado_em',
            'ja_publicado_em', 'ja_publicado_slug', 'ja_ig_em', 'ja_ig_shortcode',
        ]);

        $eventos = $this->montarEventos(collect($page->items()));

        return response()->json([
            'data' => $eventos,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'votados' => $this->contarVotados($request),
                'total_triagem' => $this->contarTotal($request),
            ],
        ]);
    }

    // ───────────────────── decaimento temporal (v4.1) ─────────────────────

    /**
     * Idade em horas da publicação original (data_pub; fallback created_at), em SQL.
     * Guard: data_pub FUTURA (feed com data errada) cai pro created_at — senão a
     * idade fica negativa e o decaimento daria nota cheia eterna no topo.
     */
    private static function idadeHorasSql(): string
    {
        return "((julianday('now') - (case when data_pub is not null and julianday(data_pub) <= julianday('now')
            then julianday(data_pub) else julianday(created_at) end)) * 24.0)";
    }

    /** data_pub/created_at do LÍDER do evento (mesma escolha do montarEventos), em SQL. */
    private static function liderDataSql(): string
    {
        return "(case when jr_link_extracao.cluster_id is null then coalesce(jr_link_extracao.data_pub, jr_link_extracao.created_at)
            else (select coalesce(m.data_pub, m.created_at) from jr_link_extracao m
                  where m.cluster_id = jr_link_extracao.cluster_id and m.duplicada = 0
                  order by m.score desc, coalesce(m.data_pub, m.created_at) desc limit 1) end)";
    }

    /** Score do evento = MAX(score_editorial) dos membros (mesmo do montarEventos), em SQL. */
    private static function scoreEventoSql(): string
    {
        return "(case when jr_link_extracao.cluster_id is null then coalesce(jr_link_extracao.score_editorial, -1)
            else coalesce((select max(m.score_editorial) from jr_link_extracao m
                  where m.cluster_id = jr_link_extracao.cluster_id and m.duplicada = 0), -1) end)";
    }

    /** CASE do fator de decaimento, montado da config (editável sem deploy de código). */
    private static function fatorSql(): string
    {
        $idade = "((julianday('now') - julianday(" . self::liderDataSql() . ')) * 24.0)';
        $sql = 'CASE';
        foreach ((array) config('jrlink.radar.decaimento', []) as $f) {
            $sql .= sprintf(' WHEN %s < %d THEN %.4f', $idade, (int) $f['ate_horas'], (float) $f['fator']);
        }

        return $sql . sprintf(' ELSE %.4f END', (float) config('jrlink.radar.decaimento_apos', 0.4));
    }

    /** Fator de decaimento em PHP — mesma régua do SQL, pro score_atual exibido. */
    public static function fatorDecaimento(?float $idadeHoras): float
    {
        if ($idadeHoras === null) {
            return 1.0;
        }
        foreach ((array) config('jrlink.radar.decaimento', []) as $f) {
            if ($idadeHoras < (int) $f['ate_horas']) {
                return (float) $f['fator'];
            }
        }

        return (float) config('jrlink.radar.decaimento_apos', 0.4);
    }

    /** Idade em horas de um datetime string heterogêneo; null se imprestável. */
    public static function idadeHoras(?string $dt): ?float
    {
        if ($dt === null || trim($dt) === '') {
            return null;
        }
        try {
            return now()->diffInHours(\Illuminate\Support\Carbon::parse($dt), true);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Subquery: existe voto humano em qualquer item do evento desta row. */
    private static function subVoto($w): void
    {
        $w->select(DB::raw(1))->from('jr_pauta_feedback as f')
            ->join('jr_link_extracao as e2', 'e2.id', '=', 'f.jr_link_extracao_id')
            ->whereRaw('(jr_link_extracao.cluster_id is not null and e2.cluster_id = jr_link_extracao.cluster_id) or f.jr_link_extracao_id = jr_link_extracao.id');
    }

    /** Total de eventos do filtro corrente (sem o filtro nao_votados) — contador de triagem. */
    private function contarTotal(Request $request): int
    {
        return $this->queryTriagem($request)->count();
    }

    private function contarVotados(Request $request): int
    {
        return $this->queryTriagem($request)->whereExists(fn ($w) => self::subVoto($w))->count();
    }

    /** Mesmo filtro do index SEM nao_votados (pro contador votados/total). */
    private function queryTriagem(Request $request)
    {
        $secao = $request->query('secao', 'quentes');
        $janela = min(168, max(6, (int) $request->query('janela', 48)));
        $cutoff = now()->subHours($janela);

        $q = DB::table('jr_link_extracao')
            ->where('duplicada', false)
            ->where(function ($w) {
                $w->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->where(function ($w) use ($cutoff) {
                $w->where('data_pub', '>=', $cutoff->toDateString())
                    ->orWhere(function ($ww) use ($cutoff) {
                        $ww->whereNull('data_pub')->where('created_at', '>=', $cutoff);
                    });
            });

        if ($secao === 'fila') {
            $q->where('temperatura_juiz', 'fila_humana');
        } elseif ($secao !== 'geral') {
            $q->whereRaw("coalesce(temperatura_juiz, temperatura) = 'quente'");
        }
        if (in_array($request->query('eixo'), ['primaria', 'concorrente'], true)) {
            $q->where('eixo', $request->query('eixo'));
        }
        if (($min = (int) $request->query('score_min', 0)) > 0) {
            $q->where('score_editorial', '>=', $min);
        }
        if (($cidade = trim((string) $request->query('cidade'))) !== '') {
            $q->where('cidade_llm', 'like', '%' . $cidade . '%');
        }
        if (($busca = trim((string) $request->query('q'))) !== '') {
            $q->where(function ($w) use ($busca) {
                $w->where('titulo', 'like', '%' . $busca . '%')
                    ->orWhere('juiz_motivo', 'like', '%' . $busca . '%');
            });
        }
        if ($request->query('publicadas') !== '1') {
            $q->whereNull('ja_publicado_em');
        }
        if (($maxIdade = (int) $request->query('max_idade_h', 0)) > 0) {
            $q->whereRaw(self::idadeHorasSql() . ' < ?', [$maxIdade]);
        }

        return $q;
    }

    /**
     * Transforma a página de representantes em EVENTOS (membros agregados em
     * 2 queries — sem N+1): líder, fontes distintas, cobertura, voto.
     */
    private function montarEventos($reps)
    {
        $clusterIds = $reps->pluck('cluster_id')->filter()->unique();
        $membros = $clusterIds->isEmpty() ? collect() : DB::table('jr_link_extracao')
            ->whereIn('cluster_id', $clusterIds)->where('duplicada', false)
            ->get(['id', 'cluster_id', 'titulo', 'url', 'host', 'fonte_tipo',
                'score', 'score_editorial', 'data_pub', 'created_at'])
            ->groupBy('cluster_id');

        // Voto por evento = voto registrado em QUALQUER item do cluster (último vence).
        $idsTodos = $reps->pluck('id')->merge($membros->flatten(1)->pluck('id'));
        $votos = DB::table('jr_pauta_feedback')->whereIn('jr_link_extracao_id', $idsTodos)
            ->orderBy('votado_em')->get(['jr_link_extracao_id', 'faixa']);
        $votoPorItem = $votos->pluck('faixa', 'jr_link_extracao_id');

        return $reps->map(function ($rep) use ($membros, $votoPorItem) {
            $grupo = $rep->cluster_id ? collect($membros[$rep->cluster_id] ?? [$rep]) : collect([$rep]);

            // Líder: maior score (coarse, cobre membro não-julgado); desempate mais recente.
            $lider = $grupo->sortBy([
                fn ($a, $b) => (int) $b->score <=> (int) $a->score,
                fn ($a, $b) => strcmp((string) ($b->data_pub ?? $b->created_at), (string) ($a->data_pub ?? $a->created_at)),
            ])->first() ?? $rep;

            // Fontes distintas: melhor item de cada fonte (pra linkar a cobertura).
            $fontes = $grupo->groupBy(fn ($m) => $m->fonte_tipo ?: $m->host ?: '?')
                ->map(fn ($g, $nome) => [
                    'nome' => $nome,
                    'url' => $g->sortByDesc('score')->first()->url,
                ])->values();

            $datas = $grupo->map(fn ($m) => $m->data_pub ?: $m->created_at)->sort()->values();
            $faixaVoto = $grupo->pluck('id')->map(fn ($id) => $votoPorItem[$id] ?? null)->filter()->last();

            // v4.1: decaimento pela idade da publicação original do LÍDER —
            // exibição/ordenação apenas; o score do juiz não muda no banco.
            $scoreJuiz = (int) $grupo->max(fn ($m) => (int) ($m->score_editorial ?? -1)) >= 0
                ? (int) $grupo->max(fn ($m) => (int) ($m->score_editorial ?? -1))
                : null;
            $idadeHoras = self::idadeHoras($lider->data_pub ?: $lider->created_at)
                ?? self::idadeHoras($lider->created_at);
            $scoreAtual = $scoreJuiz !== null
                ? (int) round($scoreJuiz * self::fatorDecaimento($idadeHoras))
                : null;

            return [
                'evento_id' => $rep->cluster_id ?: ('item-' . $rep->id),
                'lider_id' => (int) $lider->id,
                'titulo' => $lider->titulo,
                'url' => $lider->url,
                'score_evento' => $scoreJuiz,
                'score_atual' => $scoreAtual,
                'idade_horas' => $idadeHoras !== null ? round($idadeHoras, 1) : null,
                'score_coarse' => (int) $grupo->max('score'),
                'eixo' => $rep->eixo,
                'escopo' => $rep->escopo,
                'tipo_gancho' => $rep->tipo_gancho,
                'cidade_llm' => $rep->cidade_llm,
                'juiz_motivo' => $rep->juiz_motivo,
                'temperatura_final' => $rep->temperatura_juiz ?: $rep->temperatura,
                'n_portais' => $fontes->count(),
                'fontes' => $fontes,
                'primeira_cobertura' => $datas->first(),
                'ultima_cobertura' => $datas->last(),
                'publicado_em' => $rep->data_pub ?: null,
                'created_at' => $rep->created_at,
                'notificado_em' => $rep->notificado_em,
                'ja_publicado_em' => $rep->ja_publicado_em ?? null,
                'ja_publicado_slug' => $rep->ja_publicado_slug ?? null,
                'ja_ig_em' => $rep->ja_ig_em ?? null,
                'ja_ig_shortcode' => $rep->ja_ig_shortcode ?? null,
                'faixa_voto' => $faixaVoto,
            ];
        })->values();
    }

    /**
     * EM ALTA — trending SEM IA: nº de fontes distintas ponderado por recência
     * (cobertura < 24h conta 1.0; 24-48h conta 0.5). Entram eventos com
     * >= jrlink.radar.alta_min_fontes (default 3) fontes na janela.
     */
    private function emAlta($cutoff): JsonResponse
    {
        $minFontes = (int) config('jrlink.radar.alta_min_fontes', 3);

        $itens = DB::table('jr_link_extracao')
            ->whereNotNull('cluster_id')->where('duplicada', false)
            ->where(function ($w) use ($cutoff) {
                $w->where('data_pub', '>=', $cutoff->toDateString())
                    ->orWhere(function ($ww) use ($cutoff) {
                        $ww->whereNull('data_pub')->where('created_at', '>=', $cutoff);
                    });
            })
            ->get(['id', 'cluster_id', 'fonte_tipo', 'host', 'data_pub', 'created_at']);

        $corte24 = now()->subHours(24);
        $trending = $itens->groupBy('cluster_id')->map(function ($grupo, $clusterId) use ($corte24) {
            $porFonte = $grupo->groupBy(fn ($m) => $m->fonte_tipo ?: $m->host ?: '?');
            $score = $porFonte->map(function ($g) use ($corte24) {
                $maisRecente = $g->map(fn ($m) => $m->data_pub ?: $m->created_at)->max();
                return $maisRecente >= $corte24->format('Y-m-d H:i:s') ? 1.0 : 0.5;
            })->sum();

            return ['cluster_id' => (int) $clusterId, 'n_fontes' => $porFonte->count(), 'trending' => $score];
        })->filter(fn ($t) => $t['n_fontes'] >= $minFontes)->sortByDesc('trending')->take(20);

        if ($trending->isEmpty()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $reps = DB::table('jr_link_extracao')
            ->whereIn('cluster_id', $trending->pluck('cluster_id'))
            ->where('cluster_rep', true)
            // Nacional PURO fora do EM ALTA: pauta de agência (Mega-Sena, etanol)
            // que todo portal replica não é "todo mundo cobrindo" editorial.
            // nacional_localizado (tainha) e regional ficam; sem juiz ainda, fica.
            ->where(function ($w) {
                $w->whereNull('escopo')->orWhere('escopo', '!=', 'nacional');
            })
            ->get(['id', 'titulo', 'url', 'host', 'fonte_tipo', 'origem', 'eixo',
                'temperatura', 'temperatura_juiz', 'score', 'score_editorial',
                'escopo', 'tipo_gancho', 'cidade_llm', 'tema_ga4', 'juiz_motivo',
                'cluster_id', 'data_pub', 'created_at', 'notificado_em']);

        $eventos = $this->montarEventos($reps)->map(function ($e) use ($trending) {
            $e['score_trending'] = $trending[$e['evento_id']]['trending'] ?? 0;

            return $e;
        })->sortByDesc('score_trending')->values();

        return response()->json(['data' => $eventos, 'meta' => ['total' => $eventos->count()]]);
    }
}
