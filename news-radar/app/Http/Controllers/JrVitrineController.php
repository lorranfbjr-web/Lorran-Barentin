<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vitrine "Radar JR" — server-rendered. Lê jr_link_extracao NA HORA (sempre
 * fresca) e renderiza SEM chamar LLM: o agrupamento por assunto (assunto_id) já
 * foi calculado pelo Opus no ciclo (jrlink:assuntos). Aplica os mesmos guardas
 * do digest: só eh_pauta=1 quente, esconde já-publicado e fato-velho/frio, e
 * decaimento temporal (score_atual = score × fator pela idade). Agrupa por
 * assunto: assunto com N portais = UM bloco (tainha = 1, não 13).
 */
class JrVitrineController extends Controller
{
    /** Editoria (rótulo + cor V5.1) por tema_ga4 do juiz. */
    private const EDITORIA = [
        'seguranca'         => ['Segurança', '#E63946'],
        'transito'          => ['Trânsito', '#E63946'],
        'politica'          => ['Política', '#0D2481'],
        'economia_negocios' => ['Economia', '#2D6A4F'],
        'meio_ambiente'     => ['Meio Ambiente', '#40916C'],
        'animais'           => ['Meio Ambiente', '#40916C'],
        'saude'             => ['Saúde', '#48CAE4'],
        'feel_good_gente'   => ['Entretenimento', '#E056A0'],
        'famosos'           => ['Entretenimento', '#E056A0'],
        'turismo'           => ['Especiais', '#D4A373'],
        'outros'            => ['Especiais', '#D4A373'],
    ];

    private const ORIGEM_LABEL = [
        'feed' => 'Portais', 'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram',
    ];

    /** Palavra forte de crime no título → editoria Segurança (regex sem acento-sensível). */
    private static function pareceCrime(string $titulo): bool
    {
        $t = mb_strtolower($titulo);

        return (bool) preg_match(
            '/\b(assalt|roub|furt|latroc[ií]n|homic[ií]d|assassin|esfaque|balead|tiro|tiros|'
            . 'a tiros|sequestr|estupr|feminic[ií]d|chacina|tortur|espancad|agress|'
            . 'arma de fogo|encapuzad|mascarad|refém|ref[eé]ns)/u',
            $t
        );
    }

    /** Título indica mídia (vídeo/câmera/flagrante) que justifica gancho viral. */
    private static function temMidia(string $titulo): bool
    {
        $t = mb_strtolower($titulo);

        return (bool) preg_match(
            '/\b(v[ií]deo|v[ií]deos|c[âa]mera|c[âa]meras|imagens|grava[çc][ãa]o|filmou|'
            . 'filmad|flagr|assista|veja o momento|momento em que|registrou|gravou|'
            . 'circula nas redes|viraliz)/u',
            $t
        );
    }

    public function index(Request $request): View
    {
        $horas = min(168, max(6, (int) $request->query('horas', 48)));
        $cut = Carbon::now()->subHours($horas);
        // O Instagram é o canal NOVO (baixo volume) — puxa numa janela maior (7d)
        // pra aba IG mostrar o canal inteiro; feed/whatsapp ficam frescos na janela.
        $cutIg = Carbon::now()->subHours(max($horas, 168));

        // Representantes eh_pauta=1 QUENTE, NÃO já-publicado (guardas), na janela.
        // (fato-velho já entra como frio no juiz → temperatura_juiz='quente' o exclui.)
        $reps = DB::table('jr_link_extracao')
            ->where('eh_pauta', 1)
            ->where('temperatura_juiz', 'quente')
            ->whereNull('ja_publicado_em')
            ->where('duplicada', false)
            ->where(function ($w) {
                $w->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->where(function ($w) use ($cut, $cutIg) {
                $w->where(function ($f) use ($cut) {
                    $f->where('origem', '!=', 'instagram')
                        ->where(function ($ww) use ($cut) {
                            $ww->where('data_pub', '>=', $cut->toDateString())
                                ->orWhere(function ($x) use ($cut) {
                                    $x->whereNull('data_pub')->where('created_at', '>=', $cut);
                                });
                        });
                })->orWhere(function ($f) use ($cutIg) {
                    $f->where('origem', 'instagram')
                        ->where(function ($ww) use ($cutIg) {
                            $ww->where('data_pub', '>=', $cutIg->toDateString())->orWhere('created_at', '>=', $cutIg);
                        });
                });
            })
            ->get(['id', 'titulo', 'url', 'host', 'origem', 'cluster_id', 'assunto_id', 'assunto_label',
                'score_editorial', 'cidade_llm', 'tema_ga4', 'tipo_gancho', 'juiz_motivo', 'data_pub', 'created_at']);

        // BLOCO 4 (simplificar 03/07): rede de segurança SÍNCRONA no topo da
        // vitrine — a flag ja_publicado_em depende do sync de 30min; aqui o
        // matcher barato (título+URL, sem LLM, corpus em cache) segura o que
        // o sync ainda não marcou. Matéria nossa não disputa o topo.
        $reps = $reps->filter(fn ($r) => \App\Services\Jr\PublicadoMatcher::casaBarato((string) $r->titulo, (string) $r->url) === null)->values();

        // Portais (fontes distintas) por cluster — 1 query, sem N+1.
        $clusterIds = $reps->pluck('cluster_id')->filter()->unique()->values();
        $membros = $clusterIds->isEmpty() ? collect() : DB::table('jr_link_extracao')
            ->whereIn('cluster_id', $clusterIds)->where('duplicada', false)
            ->get(['cluster_id', 'url', 'host', 'fonte_tipo', 'score'])
            ->groupBy('cluster_id');

        // Agrupa reps por assunto_id (singleton = id próprio).
        $porAssunto = $reps->groupBy(fn ($r) => $r->assunto_id ?: ('i' . $r->id));

        $assuntos = $porAssunto->map(function ($grupo) use ($membros) {
            // Líder do assunto = maior score_atual (decaimento aplicado).
            $comAtual = $grupo->map(function ($r) {
                $idade = JrRadarController::idadeHoras($r->data_pub ?: $r->created_at);
                $r->_idade = $idade;
                $r->_atual = (int) round((int) $r->score_editorial * JrRadarController::fatorDecaimento($idade));

                return $r;
            });
            $lider = $comAtual->sortByDesc('_atual')->first();

            // Fontes distintas de TODOS os clusters do assunto (N portais cobrindo).
            $fontes = collect();
            foreach ($grupo->pluck('cluster_id')->filter()->unique() as $cid) {
                foreach (($membros[$cid] ?? collect()) as $m) {
                    $fontes->push($m);
                }
            }
            $portais = $fontes->isEmpty()
                ? collect([['nome' => $lider->host ?: '?', 'url' => $lider->url]])
                : $fontes->groupBy(fn ($m) => $m->fonte_tipo ?: $m->host ?: '?')
                    ->map(fn ($g, $nome) => ['nome' => $nome, 'url' => $g->sortByDesc('score')->first()->url])
                    ->values();

            [$edLabel, $edCor] = self::EDITORIA[$lider->tema_ga4] ?? ['Geral', '#0061FF'];
            // Correção determinística de editoria (NÃO toca o juiz): assalto a
            // comércio etc. costuma cair em "economia_negocios"/"outros". Se o
            // título tem palavra forte de crime e a editoria do juiz é
            // economia/outros/geral, exibe Segurança. Não mexe em casos que o juiz
            // já acertou (seguranca, politica…) nem reescreve o banco.
            if (in_array($lider->tema_ga4, ['economia_negocios', 'outros', null], true)
                && self::pareceCrime((string) $lider->titulo)) {
                [$edLabel, $edCor] = self::EDITORIA['seguranca'];
            }
            // Sinal "viral sem mídia confirmada": gancho viral/curiosidade mas o
            // título não indica vídeo/câmera/flagrante que justifique o hype.
            $semMidia = in_array($lider->tipo_gancho, ['viral', 'curiosidade'], true)
                && ! self::temMidia((string) $lider->titulo);

            return [
                'assunto_id' => $lider->assunto_id ?: ('i' . $lider->id),
                'label' => $lider->assunto_label ?: $lider->titulo,
                'titulo' => $lider->titulo,
                'url' => $lider->url,
                'host' => $lider->host,
                'origem' => $lider->origem,
                'origem_label' => self::ORIGEM_LABEL[$lider->origem] ?? $lider->origem,
                'cidade' => $lider->cidade_llm,
                'editoria' => $edLabel,
                'editoria_cor' => $edCor,
                'tema' => $lider->tema_ga4,
                'sem_midia' => $semMidia,
                'motivo' => $lider->juiz_motivo,
                'score' => (int) $lider->score_editorial,
                'score_atual' => $lider->_atual,
                'idade_horas' => $lider->_idade,
                'n_portais' => $portais->count(),
                'n_frentes' => $grupo->count(),
                'portais' => $portais->take(8)->all(),
                'origens' => $grupo->pluck('origem')->unique()->values()->all(),
            ];
        })->values()
            ->sortByDesc('score_atual')->values();

        // Blocos: "Agora" (líder < 6h) e "Últimas 24h" (resto da janela).
        $agora = $assuntos->filter(fn ($a) => $a['idade_horas'] !== null && $a['idade_horas'] < 6)->values();
        $recentes = $assuntos->filter(fn ($a) => $a['idade_horas'] === null || $a['idade_horas'] >= 6)->values();

        // ── DETECTOR DE VIRAL v0 (D3, 02/07/2026) — PASSIVO, server-rendered,
        // sem push. Aceleração por assunto_id: taxa itens/hora na janela de 3h
        // vs a taxa das 21h anteriores + nº de portais distintos em 3h.
        // "Acelerando" = ≥3 itens E ≥2 portais nas 3h E taxa 3h > 2× a anterior.
        $c3 = Carbon::now()->subHours(3);
        $c24 = Carbon::now()->subHours(24);
        $acel = DB::table('jr_link_extracao')
            ->whereNotNull('assunto_id')
            ->where('created_at', '>=', $c24)
            ->selectRaw(
                'assunto_id,
                 SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as n3h,
                 COUNT(*) as n24h,
                 COUNT(DISTINCT CASE WHEN created_at >= ? THEN host END) as portais3h',
                [$c3, $c3]
            )
            ->groupBy('assunto_id')
            ->havingRaw('n3h >= 3')
            ->get()
            ->filter(function ($r) {
                $taxa3h = $r->n3h / 3.0;
                $taxaAntes = max(0.05, ($r->n24h - $r->n3h) / 21.0); // piso evita div. por ~0
                return $r->portais3h >= 2 && $taxa3h > 2 * $taxaAntes;
            })
            ->sortByDesc(fn ($r) => $r->n3h)
            ->keyBy('assunto_id');

        // Cruza com os assuntos da vitrine (label/link vêm do card existente).
        $acelerando = $assuntos
            ->filter(fn ($a) => isset($acel[$a['assunto_id']]))
            ->map(function ($a) use ($acel) {
                $m = $acel[$a['assunto_id']];
                $a['acel_n3h'] = (int) $m->n3h;
                $a['acel_n24h'] = (int) $m->n24h;
                $a['acel_portais'] = (int) $m->portais3h;

                return $a;
            })
            ->sortByDesc('acel_n3h')->take(6)->values();

        $stats = [
            'processados' => DB::table('jr_link_extracao')->where('created_at', '>=', $cut)->count(),
            'julgados' => DB::table('jr_link_extracao')->where('juiz_julgado_em', '>=', $cut)->count(),
            'quentes' => $assuntos->count(),
            'instagram' => $assuntos->filter(fn ($a) => in_array('instagram', $a['origens'], true))->count(),
            'atualizado' => Carbon::now('America/Sao_Paulo')->format('d/m H:i'),
            'janela_h' => $horas,
        ];

        // Cidades e editorias presentes (pros filtros client-side).
        $cidades = $assuntos->pluck('cidade')->filter()->unique()->sort()->values()->all();
        $editorias = $assuntos->pluck('editoria')->unique()->sort()->values()->all();

        return view('vitrine', [
            'acelerando' => $acelerando,
            'agora' => $agora,
            'recentes' => $recentes,
            'stats' => $stats,
            'cidades' => $cidades,
            'editorias' => $editorias,
            'key' => (string) $request->query('key', ''),
        ]);
    }
}
