<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;

/**
 * Guarda de FATO-VELHO-REDATADO (v4.3): detecta, barato, quando o fato CENTRAL
 * de uma matéria é ANTIGO sendo apenas re-noticiado — lei já sancionada/em
 * vigor, efeméride, retrospectiva/balanço, ou data interna muito anterior ao
 * published_at. Um portal re-publica e o published_at vem recente, então o
 * decaimento do painel acha que é nova. Esta heurística devolve um MOTIVO
 * (string) quando há sinal; o juiz LLM (Opus) recebe o motivo NO INPUT do item
 * e decide o veredito final — o prompt base do juiz não muda.
 *
 * Barato de propósito: regex sobre título + lead do corpo. Sem I/O, sem LLM.
 */
class FatoVelho
{
    /** Quantos dias uma data interna precisa anteceder o published_at pra contar como "velho". */
    private const DIAS_VELHO = 40;

    private const MESES = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9,
        'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    /**
     * @return string|null  motivo curto se o fato central parece antigo; null se nada
     */
    public function analisar(string $titulo, ?string $corpo, ?string $publishedAt): ?string
    {
        $lead = mb_strtolower(mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $corpo)), 0, 1200));
        $tit = mb_strtolower($titulo);
        $texto = $tit . ' ' . $lead;

        // Título que anuncia DESFECHO/OCORRÊNCIA FRESCA (condenação, prisão, morte,
        // acidente…) é notícia nova por definição — datas/leis antigas no corpo são
        // só a base do fato (crime de 2025 julgado agora, lei sob a qual foi
        // condenado). Esses casos, se já publicados, são barrados pelo MATCH, não
        // por aqui. Não levantamos sinal de fato-velho pra eles.
        if ($this->tituloDesfechoFresco($tit)) {
            return null;
        }

        $pub = $this->parseData($publishedAt);

        // (1) DATA INTERNA explícita muito anterior ao published_at, perto do lead.
        if ($pub && ($achada = $this->dataVelhaNoLead($lead, $pub)) !== null) {
            return sprintf('data interna do fato (%s) ~%d dias antes da publicação', $achada['rotulo'], $achada['dias']);
        }

        // (2) MARCO LEGISLATIVO — lei/norma já em vigor não é, por si, fato novo.
        if (preg_match('/\b(sancion\w+|promulg\w+|lei\s+(j[áa]\s+)?aprovada|passa(r)?\s+a\s+valer|entra(r|ndo)?\s+em\s+vigor|nova\s+(lei|norma|legisla\w+)|já\s+est[áa]\s+em\s+vigor)\b/u', $texto)) {
            return 'marco legislativo (lei sancionada/em vigor — pode não ser acontecimento dos últimos dias)';
        }

        // (3) EFEMÉRIDE / RETROSPECTIVA / BALANÇO.
        if (preg_match('/\bh[áa]\s+\d+\s+anos\b|\b\d+\s+anos\s+atr[áa]s\b|\bcomplet\w+\s+\d+\s+anos\b|\banivers[áa]rio\s+de\b|\brelembr\w+\b|\bretrospectiva\b|\bbalan[çc]o\s+de\s+\d{4}\b|\brevej\w+\b|\bh[áa]\s+\d+\s+(meses|m[êe]s)\b/u', $texto)) {
            return 'efeméride/retrospectiva (recapitulação de fato anterior)';
        }

        return null;
    }

    /** Acha a data mais antiga e relevante no lead que anteceda o published_at em > DIAS_VELHO. */
    private function dataVelhaNoLead(string $lead, Carbon $pub): ?array
    {
        $cands = [];

        // "DD de MÊS [de YYYY]"
        if (preg_match_all('/(\d{1,2})\s+de\s+([a-zç]+)(?:\s+de\s+(\d{4}))?/u', $lead, $m, PREG_SET_ORDER)) {
            foreach ($m as $g) {
                $mes = self::MESES[$g[2]] ?? null;
                if (! $mes) {
                    continue;
                }
                $ano = isset($g[3]) && $g[3] !== '' ? (int) $g[3] : (int) $pub->year;
                $cands[] = [Carbon::create($ano, $mes, (int) $g[1]), sprintf('%02d/%02d/%d', (int) $g[1], $mes, $ano)];
            }
        }
        // "DD/MM/YYYY" ou "DD/MM/YY"
        if (preg_match_all('#\b(\d{1,2})/(\d{1,2})/(\d{2,4})\b#', $lead, $m, PREG_SET_ORDER)) {
            foreach ($m as $g) {
                $ano = (int) $g[3];
                $ano = $ano < 100 ? 2000 + $ano : $ano;
                $cands[] = [Carbon::create($ano, (int) $g[2], (int) $g[1]), sprintf('%02d/%02d/%d', (int) $g[1], (int) $g[2], $ano)];
            }
        }
        // "MÊS de YYYY" (sem dia)
        if (preg_match_all('/\b([a-zç]+)\s+de\s+(\d{4})\b/u', $lead, $m, PREG_SET_ORDER)) {
            foreach ($m as $g) {
                $mes = self::MESES[$g[1]] ?? null;
                if ($mes) {
                    $cands[] = [Carbon::create((int) $g[2], $mes, 1), sprintf('%02d/%d', $mes, (int) $g[2])];
                }
            }
        }

        $melhor = null;
        foreach ($cands as [$data, $rotulo]) {
            if (! $data instanceof Carbon) {
                continue;
            }
            $dias = $data->diffInDays($pub, false); // positivo se data ANTES do pub
            if ($dias > self::DIAS_VELHO && $dias < 3650) {
                if ($melhor === null || $dias > $melhor['dias']) {
                    $melhor = ['dias' => (int) $dias, 'rotulo' => $rotulo];
                }
            }
        }

        return $melhor;
    }

    /** Título anuncia desfecho/ocorrência recente — data antiga no corpo é o fato-base, não a notícia. */
    private function tituloDesfechoFresco(string $tit): bool
    {
        return (bool) preg_match('/\b(conden\w+|julgad\w+|sentenciad\w+|pres[oa]s?\b|pris[ãa]o|detid\w+|morre\w*|mort[oae]s?\b|morto|matou|assassin\w+|acidente|resgat\w+|apreend\w+|flagrante|opera[çc][ãa]o|den[úu]ncia|preso\s+em|capturad\w+|encontrad[oa]\s+mort)/u', $tit);
    }

    private function parseData(?string $dt): ?Carbon
    {
        if ($dt === null || trim($dt) === '') {
            return null;
        }
        try {
            return Carbon::parse($dt);
        } catch (\Throwable) {
            return null;
        }
    }
}
