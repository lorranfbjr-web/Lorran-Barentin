<?php

namespace App\Console\Commands;

use App\Services\Jr\EventClusterer;
use App\Services\Jr\JuizLlm;
use App\Support\TituloFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v5 — AGRUPAMENTO POR ASSUNTO (vitrine Radar). Roda no CICLO (depois do juiz),
 * NUNCA no request da página. Agrupa CLUSTERS diferentes do MESMO assunto numa
 * janela de 7 dias (ex.: tainha = encerramento + reabertura + cota + Lula = 1)
 * e persiste assunto_id (estável) + assunto_label (legível) nos itens.
 *
 * Estratégia híbrida (mesma filosofia do cluster-merge): pré-grupo BARATO por
 * overlap de tokens do título (idf, sem custo) junta os clusters do mesmo
 * assunto; o Opus só ENTRA pra ROTULAR cada grupo multi-cluster (label PT-BR
 * legível). Singletons recebem o próprio título como label, sem LLM. O que já
 * tem assunto_id estável é cacheado (não re-rotula). Logado em jr_juiz_log como
 * assunto_group. NÃO toca o prompt do juiz nem o pipeline de cluster.
 */
class JrAssuntos extends Command
{
    /** Overlap idf mínimo p/ dois eventos serem do MESMO assunto (mais frouxo que evento). */
    private const SUBJECT_MIN = 0.30;

    /**
     * Tokens que NÃO ancoram assunto: geografia (cidade/região), nome de fonte e
     * vocabulário genérico de notícia (papéis, ação policial, esporte). Sem isto
     * o union-find encadeia crimes/eventos distintos via "homem/preso/operacao"
     * num assunto-monstro. Âncora válida = token específico (ex.: "tainha").
     */
    private const NAO_ANCORA = [
        // geografia SC
        'santa', 'catarina', 'catarinense', 'florianopolis', 'floripa', 'blumenau', 'balneario',
        'camboriu', 'itajai', 'itapema', 'navegantes', 'penha', 'picarras', 'brusque', 'joinville',
        'jaragua', 'joacaba', 'lages', 'chapeco', 'canoinhas', 'indaial', 'gaspar', 'palhoca',
        'biguacu', 'tijucas', 'ararangua', 'tubarao', 'laguna', 'imbituba', 'garopaba', 'oeste',
        'litoral', 'serra', 'vale', 'grande', 'alto', 'norte', 'sul', 'regiao', 'cidade', 'estado',
        'bairro', 'rua', 'brasil', 'brasileiro', 'brasileira', 'nacional', 'estadual', 'municipal',
        'jose', 'batista', 'quatro', 'ilhas', 'bombinhas', 'porto', 'belo', 'pomerode', 'timbo',
        // fontes / boilerplate
        'noticias', 'noticia', 'visor', 'portal', 'jornal', 'jmais', 'sccomvoce', 'video', 'fotos',
        'agora', 'confira', 'veja', 'assista',
        // papéis / pessoas genéricas
        'homem', 'mulher', 'jovem', 'menino', 'menina', 'crianca', 'criancas', 'idoso', 'idosa',
        'adolescente', 'casal', 'morador', 'moradora', 'pessoas', 'vitima', 'suspeito', 'suspeita',
        'motorista', 'motociclista', 'pedestre', 'homens', 'mulheres', 'dois', 'tres', 'quatro', 'cinco',
        // ação policial / judicial genérica
        'preso', 'presa', 'presos', 'prisao', 'prende', 'detido', 'detida', 'operacao', 'policia',
        'policial', 'civil', 'militar', 'pmsc', 'condenado', 'condenada', 'investigacao', 'investigado',
        'trafico', 'drogas', 'droga', 'cocaina', 'crack', 'maconha', 'furto', 'roubo', 'assalto',
        'crime', 'homicidio', 'morte', 'morto', 'morta', 'morre', 'morrem', 'mortos', 'acidente',
        'foge', 'foragido', 'tentativa', 'flagrante', 'apreende', 'apreensao', 'caso', 'denuncia',
        'acusado', 'julgado', 'audiencia', 'delegacia', 'preso',
        // esporte genérico
        'atleta', 'medalha', 'medalhas', 'campeonato', 'campeao', 'campea', 'titulo', 'etapa', 'serie',
        'podio', 'conquista', 'vence', 'jogo', 'jogos', 'time', 'clube',
        // verbos/genéricos
        'historia', 'acaba', 'termina', 'recebe', 'garante', 'leva', 'deixa', 'quase', 'falta',
        'publico', 'carro', 'casa', 'residencia',
    ];

    protected $signature = 'jrlink:assuntos '
        . '{--dias=7 : Janela de agrupamento (dias)} '
        . '{--dry : Mostra os grupos sem gravar nem chamar LLM}';

    protected $description = 'Agrupa clusters do MESMO assunto (janela 7d) com o Opus e persiste assunto_id/assunto_label (passo do ciclo, fora do request).';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');
        $dry = (bool) $this->option('dry');
        $cut = Carbon::now()->subDays($dias);

        // Eventos = representantes eh_pauta=1 quente, NÃO já-publicado, na janela.
        $reps = DB::table('jr_link_extracao')
            ->where('eh_pauta', 1)
            ->where('temperatura_juiz', 'quente')
            ->whereNull('ja_publicado_em')
            ->where('duplicada', false)
            ->where(function ($w) {
                $w->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->where(function ($w) use ($cut) {
                $w->where('data_pub', '>=', $cut->toDateString())->orWhere('created_at', '>=', $cut);
            })
            ->get(['id', 'titulo', 'cluster_id', 'assunto_id', 'assunto_label', 'score_editorial', 'data_pub', 'created_at']);

        $this->info(sprintf('Assuntos: %d eventos eh_pauta=1 na janela de %dd.', $reps->count(), $dias));
        if ($reps->isEmpty()) {
            return self::SUCCESS;
        }

        // ── pré-grupo barato: union-find por overlap idf de tokens do título ──
        $grupos = $this->preAgrupar($reps->all());
        $multi = array_filter($grupos, fn ($g) => count($g) > 1);
        $this->info(sprintf('Pré-grupo: %d assuntos (%d multi-cluster, %d singletons).',
            count($grupos), count($multi), count($grupos) - count($multi)));

        // ── plano por grupo: singletons = título; multi-cluster = Opus rotula ──
        $juiz = new JuizLlm();
        $plano = []; // assunto_id => ['label'=>?, 'membros'=>[]]
        $multi = []; // assunto_id => [títulos representativos] (vão pro Opus)
        foreach ($grupos as $g) {
            $membros = array_map(fn ($i) => $reps[$i], $g);
            $assuntoId = $this->assuntoIdDe($membros);
            $plano[$assuntoId] = ['label' => null, 'membros' => $membros];
            if (count($membros) > 1) {
                $multi[$assuntoId] = collect($membros)->sortByDesc('score_editorial')->take(3)->pluck('titulo')->all();
            } else {
                $plano[$assuntoId]['label'] = $this->labelDeTitulo($membros[0]->titulo);
            }
        }

        // UMA chamada ao Opus com TODOS os grupos multi-cluster: ele rotula cada
        // um e dá o MESMO rótulo aos que são o mesmo assunto em curso (ex.: as
        // várias frentes da tainha). Merge por rótulo normalizado depois.
        $custo = 0.0;
        if ($multi && ! $dry) {
            [$labels, $custo] = $this->rotularComOpus($juiz, $multi);
            foreach ($labels as $aid => $label) {
                if (isset($plano[$aid])) {
                    $plano[$aid]['label'] = $label;
                }
            }
        }
        foreach ($plano as $aid => &$p) {
            if ($p['label'] === null) {
                $p['label'] = $this->labelDeTitulo(collect($p['membros'])->sortByDesc('score_editorial')->first()->titulo);
            }
        }
        unset($p);

        // ── MERGE por rótulo: grupos com o MESMO rótulo do Opus viram 1 assunto ──
        if (! $dry) {
            $porLabel = [];
            foreach ($plano as $aid => $p) {
                if (count($p['membros']) > 1) {
                    $porLabel[$this->normLabel($p['label'])][] = $aid;
                }
            }
            foreach ($porLabel as $aids) {
                if (count($aids) < 2) {
                    continue;
                }
                sort($aids); // assunto_id canônico = menor (estável)
                $alvo = $aids[0];
                foreach (array_slice($aids, 1) as $outro) {
                    $plano[$alvo]['membros'] = array_merge($plano[$alvo]['membros'], $plano[$outro]['membros']);
                    unset($plano[$outro]);
                }
            }
        }

        // ── persiste (assunto_id/label/em nos clusters dos membros) ──
        $maiores = collect($plano)->filter(fn ($p) => count($p['membros']) > 1)
            ->sortByDesc(fn ($p) => count($p['membros']))->take(8);
        $this->line('Maiores assuntos:');
        foreach ($maiores as $aid => $p) {
            $this->line(sprintf('  [%d clusters] %s', count($p['membros']), mb_strimwidth((string) $p['label'], 0, 70)));
        }

        if ($dry) {
            $this->warn('DRY — nada gravado, nenhuma chamada LLM.');

            return self::SUCCESS;
        }

        $agora = Carbon::now();
        $gravados = 0;
        foreach ($plano as $aid => $p) {
            $clusterIds = collect($p['membros'])->pluck('cluster_id')->filter()->unique()->values();
            $soltos = collect($p['membros'])->whereNull('cluster_id')->pluck('id');
            if ($clusterIds->isNotEmpty()) {
                $gravados += DB::table('jr_link_extracao')->whereIn('cluster_id', $clusterIds)
                    ->update(['assunto_id' => $aid, 'assunto_label' => $p['label'], 'assunto_em' => $agora]);
            }
            if ($soltos->isNotEmpty()) {
                $gravados += DB::table('jr_link_extracao')->whereIn('id', $soltos)
                    ->update(['assunto_id' => $aid, 'assunto_label' => $p['label'], 'assunto_em' => $agora]);
            }
        }

        $this->info(sprintf('Assuntos gravados em %d linhas · %d grupos enviados ao Opus · custo US$ %.4f.',
            $gravados, count($multi), $custo));

        return self::SUCCESS;
    }

    /**
     * Union-find por overlap idf de tokens do título (subject-level, mais frouxo
     * que o EventClusterer de evento). Retorna grupos como listas de índices.
     *
     * @param  array<int,object>  $reps
     * @return array<int,int[]>
     */
    private function preAgrupar(array $reps): array
    {
        $clusterer = new EventClusterer();
        $n = count($reps);
        $tokens = [];
        $df = [];
        foreach ($reps as $i => $r) {
            $tokens[$i] = $clusterer->tokens((string) $r->titulo);
            foreach (array_keys($tokens[$i]) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $idf = [];
        foreach ($df as $t => $d) {
            $idf[$t] = log(1 + $n / $d);
        }
        $soma = [];
        foreach ($tokens as $i => $tks) {
            $s = 0.0;
            foreach (array_keys($tks) as $t) {
                $s += $idf[$t];
            }
            $soma[$i] = $s;
        }
        // índice invertido (ignora tokens em > 40% dos docs — genéricos demais)
        $inv = [];
        foreach ($tokens as $i => $tks) {
            foreach (array_keys($tks) as $t) {
                if ($df[$t] <= max(2, (int) ceil($n * 0.4))) {
                    $inv[$t][] = $i;
                }
            }
        }

        $pai = range(0, $n - 1);
        $find = function (int $x) use (&$pai, &$find): int {
            while ($pai[$x] !== $x) {
                $pai[$x] = $pai[$pai[$x]];
                $x = $pai[$x];
            }

            return $x;
        };
        // Token-ÂNCORA de assunto: distintivo o bastante (df baixo) — "tainha",
        // "feminicidio", "esgoto". Geo/ação genéricos ("santa", "catarina",
        // "operacao", "video") NÃO ancoram, senão encadeiam tudo. A aresta exige
        // âncora compartilhada E overlap acima do corte — mata o chain transitivo.
        $tetoAncora = max(3, (int) ceil($n * 0.12));
        $vistos = [];
        foreach ($inv as $docs) {
            $m = count($docs);
            for ($a = 0; $a < $m; $a++) {
                for ($b = $a + 1; $b < $m; $b++) {
                    $i = $docs[$a];
                    $j = $docs[$b];
                    $key = $i . ':' . $j;
                    if (isset($vistos[$key])) {
                        continue;
                    }
                    $vistos[$key] = true;
                    if (($soma[$i] <= 0) || ($soma[$j] <= 0)) {
                        continue;
                    }
                    $shared = 0.0;
                    $temAncora = false;
                    foreach (array_keys(count($tokens[$i]) < count($tokens[$j]) ? $tokens[$i] : $tokens[$j]) as $t) {
                        if (isset($tokens[$i][$t], $tokens[$j][$t])) {
                            $shared += $idf[$t];
                            if (($df[$t] ?? PHP_INT_MAX) <= $tetoAncora && ! in_array($t, self::NAO_ANCORA, true)) {
                                $temAncora = true;
                            }
                        }
                    }
                    if ($temAncora && $shared / min($soma[$i], $soma[$j]) >= self::SUBJECT_MIN) {
                        $pai[$find($i)] = $find($j);
                    }
                }
            }
        }

        $grupos = [];
        for ($i = 0; $i < $n; $i++) {
            $grupos[$find($i)][] = $i;
        }

        return array_values($grupos);
    }

    /** assunto_id estável: 'a' + menor cluster_id (ou id solto) do grupo. */
    private function assuntoIdDe(array $membros): string
    {
        $chaves = array_map(fn ($m) => $m->cluster_id ? (int) $m->cluster_id : (int) $m->id, $membros);

        return 'a' . min($chaves);
    }

    /** Normaliza rótulo p/ merge (minúsculo, sem acento/pontuação/espaços extras). */
    private function normLabel(?string $label): string
    {
        $t = mb_strtolower(trim((string) $label));
        $t = preg_replace('/[^\p{L}\p{N} ]/u', '', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /** Label legível a partir do título (singleton): só 1ª maiúscula, sem sufixo de fonte. */
    private function labelDeTitulo(string $titulo): string
    {
        $t = trim(TituloFeatures::stripSuffix($titulo));
        $t = preg_replace('/\s+/u', ' ', $t);

        return mb_strimwidth($t, 0, 90, '…');
    }

    /**
     * Opus rotula cada grupo multi-cluster com um assunto PT-BR curto (1ª
     * maiúscula). Prompt próprio — NÃO toca o prompt do juiz. Logado como
     * assunto_group. Retorna [labels[assunto_id=>label], custo].
     */
    private function rotularComOpus(JuizLlm $juiz, array $grupos): array
    {
        $lista = '';
        $mapa = [];
        $n = 0;
        foreach ($grupos as $aid => $titulos) {
            $mapa[$n] = $aid;
            $lista .= "GRUPO {$n}:\n- " . implode("\n- ", array_map(fn ($t) => mb_strimwidth($t, 0, 100), $titulos)) . "\n\n";
            $n++;
        }
        $prompt = <<<PROMPT
        Você rotula ASSUNTOS de um radar de pautas regionais de Santa Catarina. Cada grupo abaixo reúne manchetes do mesmo evento. Dê a cada grupo um RÓTULO curto (3 a 7 palavras), em português, só a 1ª letra maiúscula, nomeando o assunto (ex.: "Pesca da tainha em SC", "Condenação por feminicídio em Abelardo Luz", "CPI do esgoto em Camboriú"). Sem aspas nem ponto final.

        REGRA DE UNIFICAÇÃO: se dois ou mais GRUPOS fazem parte do MESMO assunto em curso (ex.: encerramento, reabertura, cota e disputa política da pesca da tainha são todos "Pesca da tainha em SC"), dê a eles EXATAMENTE o mesmo rótulo, idêntico. Casos DIFERENTES do mesmo tipo (dois feminicídios distintos, dois acidentes distintos) recebem rótulos DIFERENTES (com cidade/nome pra distinguir).

        RESPONDA APENAS com um array JSON, sem texto fora dele:
        [{"grupo": <n>, "rotulo": "..."}]

        {$lista}
        PROMPT;

        $arr = $juiz->completarJson($prompt, 'assunto_group', count($grupos), $juiz->modeloFuncao('dedup'));
        $labels = [];
        foreach ($arr as $v) {
            if (isset($v['grupo'], $mapa[(int) $v['grupo']])) {
                $labels[$mapa[(int) $v['grupo']]] = mb_strimwidth(trim((string) ($v['rotulo'] ?? '')), 0, 90);
            }
        }
        $custo = (float) DB::table('jr_juiz_log')->where('operation', 'assunto_group')
            ->where('created_at', '>=', Carbon::now()->subMinutes(2))->sum('custo_usd');

        return [$labels, $custo];
    }
}
