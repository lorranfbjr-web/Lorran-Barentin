<?php

namespace App\Services\Jr;

use App\Support\TituloFeatures;

/**
 * Colapso por EVENTO (Fase 2): agrupa rows de jr_link_extracao que contam a
 * MESMA história (ex.: oceanógrafo repetido em 5 portais) pra julgar 1 vez só.
 *
 * Similaridade entre títulos = overlap ponderado por idf dos tokens (sem
 * stopwords, len >= min_token_len): sum(idf dos tokens compartilhados) /
 * min(sum idf A, sum idf B). Une no union-find quando >= overlap_min.
 *
 * Representante por cluster: primária > maior score coarse > mais recente.
 * Sem I/O — quem persiste em news_clusters é o comando.
 */
class EventClusterer
{
    private const STOPWORDS = [
        'para', 'pela', 'pelo', 'pelas', 'pelos', 'como', 'mais', 'menos', 'apos', 'após',
        'sobre', 'entre', 'contra', 'desde', 'quando', 'onde', 'quem', 'qual', 'quais',
        'este', 'esta', 'esse', 'essa', 'isso', 'isto', 'aquele', 'aquela', 'pode',
        'podem', 'deve', 'devem', 'ainda', 'depois', 'antes', 'durante', 'hoje',
        'nesta', 'neste', 'nessa', 'nesse', 'com', 'sem', 'das', 'dos', 'nas', 'nos',
        'uma', 'uns', 'umas', 'que', 'por', 'ser', 'ter', 'foi', 'sao', 'são', 'tem',
        'veja', 'saiba', 'confira', 'entenda', 'assista', 'fotos', 'video', 'vídeo',
        'anos', 'ano', 'dia', 'dias', 'nova', 'novo', 'fica', 'após',
    ];

    private float $overlapMin;

    private int $minTokenLen;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? config('jrlink.cluster', []);
        $this->overlapMin = (float) ($cfg['overlap_min'] ?? 0.5);
        $this->minTokenLen = (int) ($cfg['min_token_len'] ?? 4);
    }

    /**
     * @param  array<object>  $rows  objetos com id, titulo, eixo, score, data_pub, created_at
     * @return array<array{ids: int[], rep: int}>  clusters (inclui singletons)
     */
    public function cluster(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => trim((string) $r->titulo) !== ''));
        $n = count($rows);
        if ($n === 0) {
            return [];
        }

        // Tokens + document frequency + sinais de veto (localidade/idade).
        $tokens = [];
        $df = [];
        $locais = [];
        $idades = [];
        foreach ($rows as $i => $r) {
            $tks = $this->tokens((string) $r->titulo);
            $tokens[$i] = $tks;
            $locais[$i] = $this->locais((string) $r->titulo);
            $idades[$i] = $this->idades((string) $r->titulo);
            foreach (array_keys($tks) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }

        // idf + soma de pesos por doc.
        $idf = [];
        foreach ($df as $t => $d) {
            $idf[$t] = log(1 + $n / $d);
        }
        $somaPeso = [];
        foreach ($tokens as $i => $tks) {
            $s = 0.0;
            foreach (array_keys($tks) as $t) {
                $s += $idf[$t];
            }
            $somaPeso[$i] = $s;
        }

        // Índice invertido (ignora tokens presentes em >30% dos docs — não discriminam).
        $inv = [];
        foreach ($tokens as $i => $tks) {
            foreach (array_keys($tks) as $t) {
                if ($df[$t] <= max(2, (int) ceil($n * 0.3))) {
                    $inv[$t][] = $i;
                }
            }
        }

        // Candidatos: só pares que compartilham ao menos 1 token discriminante.
        $vistos = [];
        $pares = [];
        foreach ($inv as $t => $docs) {
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
                    if (! $this->temAncora($tokens[$i], $tokens[$j], $df, $n)) {
                        continue; // sem entidade rara em comum = histórias diferentes
                    }
                    if ($this->conflita($locais[$i], $locais[$j]) || $this->conflita($idades[$i], $idades[$j])) {
                        continue; // cidades ou idades explícitas DIFERENTES = eventos distintos
                    }
                    $ov = $this->overlap($tokens[$i], $tokens[$j], $idf, $somaPeso[$i], $somaPeso[$j]);
                    if ($ov >= $this->overlapMin) {
                        $pares[] = [$ov, $i, $j];
                    }
                }
            }
        }

        // Union-find com VETO de componente: cada componente acumula as
        // localidades/idades dos membros; fundir dois componentes com sinais
        // explícitos conflitantes é proibido. Pares mais fortes primeiro, pra
        // título-ponte genérico não costurar eventos distintos.
        usort($pares, fn ($x, $y) => $y[0] <=> $x[0]);

        $pai = range(0, $n - 1);
        $find = function (int $x) use (&$pai, &$find): int {
            while ($pai[$x] !== $x) {
                $pai[$x] = $pai[$pai[$x]];
                $x = $pai[$x];
            }

            return $x;
        };

        $compLoc = $locais;
        $compIdade = $idades;
        foreach ($pares as [$ov, $i, $j]) {
            $ra = $find($i);
            $rb = $find($j);
            if ($ra === $rb) {
                continue;
            }
            if ($this->conflita($compLoc[$ra], $compLoc[$rb]) || $this->conflita($compIdade[$ra], $compIdade[$rb])) {
                continue;
            }
            $pai[$rb] = $ra;
            $compLoc[$ra] += $compLoc[$rb];
            $compIdade[$ra] += $compIdade[$rb];
        }

        // Agrupa e escolhe representante.
        $grupos = [];
        foreach ($rows as $i => $r) {
            $grupos[$find($i)][] = $i;
        }

        $out = [];
        foreach ($grupos as $idxs) {
            $membros = array_map(fn ($i) => $rows[$i], $idxs);
            usort($membros, function ($a, $b) {
                $pa = $a->eixo === 'primaria' ? 0 : 1;
                $pb = $b->eixo === 'primaria' ? 0 : 1;
                if ($pa !== $pb) {
                    return $pa <=> $pb;          // primária primeiro
                }
                if ((int) $b->score !== (int) $a->score) {
                    return (int) $b->score <=> (int) $a->score; // maior score coarse
                }

                return strcmp((string) ($b->data_pub ?? $b->created_at), (string) ($a->data_pub ?? $a->created_at)); // mais recente
            });

            $out[] = [
                'ids' => array_map(fn ($m) => (int) $m->id, $membros),
                'rep' => (int) $membros[0]->id,
            ];
        }

        return $out;
    }

    /** @return array<string,true> conjunto de tokens normalizados do título */
    public function tokens(string $titulo): array
    {
        $t = TituloFeatures::norm(TituloFeatures::stripSuffix($titulo));
        $parts = preg_split('/[^a-z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) >= $this->minTokenLen && ! in_array($p, self::STOPWORDS, true)) {
                $out[$p] = true;
            }
        }

        return $out;
    }

    /**
     * Localidades explícitas do título: sequência Capitalizada após preposição
     * (em/no/na/de/do/da) ou prefixo "Cidade:". Genéricos (SC, Brasil…) ficam
     * de fora. Título todo em CAIXA-ALTA não informa nada (retorna vazio).
     *
     * @return array<string,true>
     */
    public function locais(string $titulo): array
    {
        $t = TituloFeatures::stripSuffix($titulo);
        $letras = preg_replace('/[^\p{L}]/u', '', $t);
        $caixaAlta = preg_replace('/[^\p{Lu}]/u', '', $t);
        if ($letras !== '' && mb_strlen($caixaAlta) / max(mb_strlen($letras), 1) > 0.7) {
            return [];
        }

        $brutos = [];
        if (preg_match_all('/\b(?:em|no|na|de|do|da)\s+((?:[\p{Lu}][\p{L}\d-]+|BR-\d+|SC-\d+)(?:\s+(?:d[aeo]s?\s+)?[\p{Lu}][\p{L}\d-]+)*)/u', $t, $m)) {
            $brutos = $m[1];
        }
        if (preg_match('/^\s*([\p{Lu}][\p{L}\d-]+(?:\s+[\p{Lu}][\p{L}\d-]+)*)\s*:/u', $t, $m)) {
            $brutos[] = $m[1];
        }

        $genericos = ['sc', 'santa catarina', 'brasil', 'sul', 'eua', 'video', 'atencao'];
        $out = [];
        foreach ($brutos as $b) {
            $k = trim(TituloFeatures::norm($b));
            if ($k !== '' && ! in_array($k, $genericos, true)) {
                $out[$k] = true;
            }
        }

        return $out;
    }

    /**
     * Idades explícitas ("3 anos", "cinco anos"): idade diferente = pessoa
     * diferente = evento diferente (veto barato pros fait-divers).
     *
     * @return array<string,true>
     */
    public function idades(string $titulo): array
    {
        $t = TituloFeatures::norm($titulo);
        $mapa = ['um' => '1', 'dois' => '2', 'tres' => '3', 'quatro' => '4', 'cinco' => '5',
            'seis' => '6', 'sete' => '7', 'oito' => '8', 'nove' => '9', 'dez' => '10'];
        $out = [];
        if (preg_match_all('/\b(\d{1,3}|' . implode('|', array_keys($mapa)) . ')\s+anos\b/u', $t, $m)) {
            foreach ($m[1] as $v) {
                $out[$mapa[$v] ?? $v] = true;
            }
        }

        return $out;
    }

    /** Veto: os dois lados têm sinal explícito e os conjuntos não se cruzam. */
    private function conflita(array $a, array $b): bool
    {
        if (! $a || ! $b) {
            return false;
        }
        foreach (array_keys($a) as $k) {
            if (isset($b[$k])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Âncora: os dois títulos precisam compartilhar ao menos um token RARO no
     * corpus (entidade do evento: "oceanografo", "enem", "schroeder"…). Vocabulário
     * genérico de tragédia ("morre", "acidente") é frequente e não ancora — evita
     * encadear acidentes distintos num cluster só.
     */
    private function temAncora(array $a, array $b, array $df, int $n): bool
    {
        $teto = max(3, (int) ceil($n * 0.04));
        foreach (array_keys(count($a) < count($b) ? $a : $b) as $t) {
            if (isset($a[$t], $b[$t]) && ($df[$t] ?? PHP_INT_MAX) <= $teto) {
                return true;
            }
        }

        return false;
    }

    /** Overlap ponderado por idf, normalizado pelo doc "menor". */
    private function overlap(array $a, array $b, array $idf, float $somaA, float $somaB): float
    {
        if ($somaA <= 0 || $somaB <= 0) {
            return 0.0;
        }
        $shared = 0.0;
        foreach (array_keys(count($a) < count($b) ? $a : $b) as $t) {
            if (isset($a[$t]) && isset($b[$t])) {
                $shared += $idf[$t] ?? 0;
            }
        }

        return $shared / min($somaA, $somaB);
    }
}
