<?php

namespace App\Services\Jr;

/**
 * Trava DURA do elo de publicação de pautas frias. Roda ANTES de qualquer
 * reescrita ou chamada de LLM — é determinística (regex), não depende do
 * julgamento do modelo. Decide se uma captura pode virar rascunho automático
 * ou se vai pra FILA HUMANA.
 *
 * Política (doc JR):
 *  - solidariedade/vaquinha/Pix/pedido de ajuda/doação/rifa → NUNCA automático.
 *  - sensível (crime/morte/política partidária) → fila humana nesta fase fria.
 *
 * Complementa (não substitui) o gancho solidariedade/vaquinha do JuizLlm, que é
 * baseado em julgamento do LLM. Aqui é defesa-em-profundidade determinística.
 */
class PautaGate
{
    /** Resultados possíveis. */
    public const OK = 'ok';
    public const FILA_SOLIDARIEDADE = 'fila_humana_solidariedade';
    public const FILA_SENSIVEL = 'fila_humana_sensivel';

    /**
     * Solidariedade / vaquinha / pedido de ajuda / Pix / doação / rifa.
     * \b nas raízes curtas pra evitar falso-positivo (ex.: "pixel", "ajuda" em
     * "ajudante" fica de fora porque exigimos contexto de pedido/financeiro).
     */
    private const RE_SOLIDARIEDADE = '/\b(vaquinha|vakinha|vaqui+nha|rifa|rifas|doaç[ãa]o|doacao|doaç[õo]es|doacoes|'
        . 'chave\s*pix|\bpix\b|qr\s*code\s*(do\s*)?pix|'
        . 'pedido\s+de\s+ajuda|pede\s+ajuda|peço\s+ajuda|preciso\s+de\s+ajuda|'
        . 'ajuda\s+(financeira|de\s+cust|para\s+custe|para\s+o\s+tratamento)|'
        . 'campanha\s+(de\s+)?(solidaried|arrecada)|arrecada[çc][ãa]o|'
        . 'corrente\s+do\s+bem|colabor[ae]\s+com|contribua\s+com|'
        . 'transfer[êe]ncia\s+(banc|para)|conta\s+para\s+dep[óo]sito)/iu';

    /**
     * Sensível: crime/violência/morte + política partidária. Nesta fase FRIA,
     * tudo isso vai pra fila humana (não vira rascunho automático).
     */
    private const RE_SENSIVEL = '/\b('
        // crime / violência / polícia
        . 'homic[íi]dio|assassinat|assassin[oa]|esfaque|balead|tiroteio|'
        . 'feminic[íi]dio|estupr|abuso\s+sexual|sequestr|'
        . 'lat[ao]roc[íi]nio|chacina|espancad|agress[ãa]o|'
        // morte / luto
        . 'morte|morreu|faleceu|faleciment|óbito|obito|cad[áa]ver|corpo\s+encontrad|'
        . 'v[íi]tima\s+fatal|atropelament[oa]\s+(fatal|com\s+morte)|'
        // política partidária / eleitoral
        . 'prefeit[oa]\s+\w+\s+(diz|afirma|critica|rebate)|vereador|deputad|'
        . 'elei[çc][ãa]o|eleitoral|campanha\s+eleitoral|partid[oa]\s+(pol[íi]tic|pt|pl|psdb|mdb)|'
        . 'candidat[oa]\s+a\s+\w+'
        . ')\b/iu';

    /**
     * @return array{0:string,1:?string} [resultado, motivo|null]
     */
    public function avaliar(string $texto): array
    {
        $t = (string) $texto;

        if (preg_match(self::RE_SOLIDARIEDADE, $t, $m)) {
            return [self::FILA_SOLIDARIEDADE, 'Trava solidariedade/vaquinha/Pix: "' . $m[0] . '"'];
        }

        if (preg_match(self::RE_SENSIVEL, $t, $m)) {
            return [self::FILA_SENSIVEL, 'Trava sensível (crime/morte/política): "' . $m[0] . '"'];
        }

        return [self::OK, null];
    }

    public function ehSolidariedade(string $texto): bool
    {
        return (bool) preg_match(self::RE_SOLIDARIEDADE, $texto);
    }
}
