<?php

namespace App\Services\Jr;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Matcher "já publicado" compartilhado (v4.3): casa eventos do Radar contra
 * alvos publicados (posts do site / posts do IG). PRÉ-FILTRO largo gera
 * candidatos (overlap idf título×título + slug + NÚMEROS fortes do título, ex.
 * "68 anos"); a DECISÃO de cada candidato é SEMPRE do LLM (Opus) em lote
 * ("mesmo FATO? compare fato/pessoas/lugar, não as palavras").
 *
 * Usado por: jrlink:publicados-sync (lote periódico) E RadarNotificador
 * (checagem síncrona NO ATO de notificar — não confia que o sync já marcou).
 */
class PublicadoMatcher
{
    private EventClusterer $clusterer;

    private PautaClassifier $clf;

    public function __construct()
    {
        $this->clusterer = new EventClusterer();
        $this->clf = new PautaClassifier();
    }

    /**
     * Casa eventos × alvos. Eventos: objetos com id, titulo, (markdown), cluster_id.
     * Alvos: objetos com chave, titulo, quando, extra.
     *
     * @return array<int,object> evento_id => alvo casado
     */
    public function casar(Collection $eventos, Collection $alvos, JuizLlm $juiz, int $capLlm, string $rotulo): array
    {
        if ($eventos->isEmpty() || $alvos->isEmpty() || $capLlm <= 0) {
            return [];
        }

        $cfg = config('jrlink.publicados');
        $overlapMin = (float) ($cfg['prefiltro_overlap_min'] ?? 0.12);
        $maxCand = (int) ($cfg['prefiltro_max_cand_por_evento'] ?? 6);

        // Tokens: eventos pelo título; alvos pelo título + palavras do SLUG.
        $evTokens = [];
        foreach ($eventos as $i => $e) {
            $evTokens[$i] = $this->tokensDe((string) $e->titulo);
        }
        $alTokens = [];
        $alExtraTxt = [];
        foreach ($alvos as $j => $a) {
            $slugTxt = $rotulo === 'site' ? ' ' . str_replace('-', ' ', (string) $a->extra) : '';
            $alTokens[$j] = $this->tokensDe(((string) $a->titulo) . $slugTxt);
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

        // Cache de "não" (7d) evita re-perguntar o mesmo par.
        $pares = array_values(array_filter($pares, function ($p) use ($eventos, $alvos) {
            return Cache::get($this->chavePar((int) $eventos[$p['ei']]->id, $alvos[$p['aj']])) !== false;
        }));

        usort($pares, fn ($a, $b) => $b['overlap'] <=> $a['overlap']);
        if (count($pares) > $capLlm) {
            $pares = array_slice($pares, 0, $capLlm);
        }
        if (! $pares) {
            return [];
        }

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

        $matches = [];
        foreach ($pares as $n => $p) {
            $eid = (int) $eventos[$p['ei']]->id;
            $alvo = $alvos[$p['aj']];
            if ($vereditos[$n] ?? false) {
                if (! isset($matches[$eid])) {
                    $matches[$eid] = $alvo;
                }
                Log::info(sprintf('[PublicadoMatcher] MESMO FATO (%s): "%s" = "%s"',
                    $rotulo, mb_strimwidth((string) $eventos[$p['ei']]->titulo, 0, 70), mb_strimwidth((string) $alvo->titulo, 0, 70)));
            } elseif (array_key_exists($n, $vereditos)) {
                Cache::put($this->chavePar($eid, $alvo), false, now()->addDays(7));
            }
        }

        return $matches;
    }

    /**
     * Tokens do EventClusterer + NÚMEROS fortes (2+ dígitos: "68", "33", anos de
     * prisão, R$ X milhões) que o tokenizador padrão descarta mas são âncoras de
     * dedup poderosas. Prefixados pra não colidir com tokens de palavra.
     *
     * @return array<string,true>
     */
    public function tokensDe(string $texto): array
    {
        $tks = $this->clusterer->tokens($texto);
        if (preg_match_all('/\d{2,}/', $texto, $m)) {
            foreach ($m[0] as $num) {
                // ignora anos puros (calendário) — não identificam o fato
                if (preg_match('/^(19|20)\d{2}$/', $num)) {
                    continue;
                }
                $tks['num' . $num] = true;
            }
        }

        return $tks;
    }

    private function lead(object $e): string
    {
        if (empty($e->markdown)) {
            return '';
        }

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $this->clf->corpoFromMarkdown((string) $e->markdown))), 0, 240);
    }

    public function chavePar(int $eventoId, object $alvo): string
    {
        return 'jrpub:nao:' . $eventoId . ':' . md5((string) $alvo->chave);
    }

    /**
     * Lote LLM "MESMO FATO? sim/não" no modelo match_publicado (Opus). Lotes de
     * 20 (prompt focado preserva a precisão). Logado como publicado_match.
     *
     * @param  array<int,array{evento:string,evento_lead:string,alvo:string}>  $itens
     * @return array<int,bool> por índice
     */
    public function julgarMesmoFato(JuizLlm $juiz, array $itens, string $rotulo): array
    {
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
}
