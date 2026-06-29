<?php

namespace App\Services\Jr;

/**
 * Extração LIMPA do objeto de um ato do DOM/SC.
 *
 * O texto cru começa com boilerplate institucional ("ESTADO DE SANTA CATARINA
 * PREFEITURA MUNICIPAL DE X ... CNPJ ... torna público ...") + ruído de extrato
 * (números de processo, endereço, etc.). Esta heurística surfacea o OBJETO REAL
 * — o que está sendo comprado/contratado/feito — pra TODOS os atos (escala:
 * firehose + busca). Pros pontuados do radar, o Sonnet ainda poli por cima.
 *
 * ISOLADO/aditivo: usado pelo conector (ingest + busca ao vivo) e pelo backfill.
 * Determinístico, sem LLM, custo zero. ⚖️ Só descreve o FATO público.
 */
class DomObjetoLimpo
{
    /** Marcadores que ENCERRAM o objeto (a partir do 1º, corta fora). */
    private const TERMINADORES = '(?:vig[êe]ncia|valor\s+(?:global|total|mensal|estimado|m[áa]ximo|do\s+contrato|contratado|unit[áa]rio)|valor\s*:|valor\s*r\$|fornecedor|contratad[oa]\s*:|vencedor|cnpj|data\s+da\s+assinatura|data\s+de\s+assinatura|assinatura\s*:|prazo\s+(?:de\s+)?(?:vig[êe]ncia|execu[çc][ãa]o)|dota[çc][ãa]o\s+or[çc]ament|fundamenta[çc][ãa]o\s+legal|amparo\s+legal|signat[áa]rios?|partes\s*:|lei\s+n?[º°]?\.?\s*14\.?133|lei\s+federal|processo\s+(?:licitat[óo]rio|administrativo)|homologa[çd])';

    /**
     * Verbos/expressões que INTRODUZEM o objeto (estratégia sem marcador). Exigem
     * o conector ("contratação DE") pra NÃO casar citação legal ("normas gerais
     * de licitação e contratação para as Administrações Públicas").
     */
    private const VERBOS = '(?:contrata[çc][ãa]o\s+(?:direta\s+)?de|aquisi[çc][ãa]o\s+de|presta[çc][ãa]o\s+de\s+servi[çc]os?|fornecimento\s+de|execu[çc][ãa]o\s+(?:da\s+obra|de)|loca[çc][ãa]o\s+de|credenciamento\s+de|compra\s+de|registro\s+de\s+pre[çc]os?\s+(?:para|visando|objetivando))';

    /** Fragmentos que denunciam citação legal/boilerplate, não objeto real. */
    private const RUIDO = '/\b(?:art\.?\s*\d|lei\s+n?[º°]?\.?\s*\d|lei\s+(?:federal|municipal|org[âa]nica)|administra[çc][õo]es\s+p[úu]blicas|fundamento\s+(?:legal|no)|normas\s+gerais|atribui[çc][õo]es\s+legais|torna\s+p[úu]blico)\b/iu';

    /**
     * Devolve o objeto legível (1 trecho curto) ou null se nada aproveitável.
     */
    public static function limpar(?string $texto, ?string $titulo = null): ?string
    {
        $t = self::normEspaco((string) $texto);
        if ($t === '') {
            return self::fallbackTitulo($titulo);
        }

        // 1) marcador "OBJETO:" — o sinal mais forte (se não for fraco/ruído).
        $obj = self::aposObjeto($t);
        if ($obj !== null && self::aproveitavel($obj)) {
            return self::finalizar($obj);
        }

        // 2) verbo que introduz o objeto (contratação DE / aquisição DE / ...).
        if (preg_match('/\b' . self::VERBOS . '\b.*/iu', $t, $m)) {
            $cand = self::cortarTerminadores($m[0]);
            if (self::aproveitavel($cand)) {
                return self::finalizar($cand);
            }
        }

        // 3) título (costuma ser "Dispensa Eletrônica Nº…", "Extrato do Termo
        //    Aditivo…" — sem graça, mas LEGÍVEL e não enganoso).
        $tit = self::fallbackTitulo($titulo);
        if ($tit !== null) {
            return $tit;
        }

        // 4) últimos recursos: objeto fraco do marcador, ou começo sem o cabeçalho.
        if ($obj !== null) {
            return self::finalizar($obj);
        }

        $semBoiler = self::tiraBoiler($t);

        return $semBoiler !== '' ? self::finalizar($semBoiler) : null;
    }

    // ───────────────────────── estratégias ─────────────────────────

    /** Texto após o primeiro "objeto[:-]" (inclui "tem por objeto X"). */
    private static function aposObjeto(string $t): ?string
    {
        if (preg_match('/\bobjeto\b\s*[:\-–]?\s*(.{8,})/iu', $t, $m)) {
            return self::cortarTerminadores($m[1]);
        }

        return null;
    }

    /** Corta tudo a partir do primeiro terminador. */
    private static function cortarTerminadores(string $s): string
    {
        $s = trim($s);
        // Offset do preg em modo /u é BYTE mas alinhado a fronteira de caractere —
        // substr (byte) corta certinho; mb_strcut arredondaria pro meio do char.
        if (preg_match('/\b' . self::TERMINADORES . '\b/iu', $s, $m, PREG_OFFSET_CAPTURE)) {
            $s = substr($s, 0, $m[0][1]);
        }

        return trim($s);
    }

    /** Candidato serve como objeto? (≥12 chars, não referencial, não citação legal) */
    private static function aproveitavel(string $s): bool
    {
        $s = trim($s);

        return mb_strlen($s) >= 12 && ! self::ehFraco($s) && ! preg_match(self::RUIDO, $s);
    }

    /** Objeto referencial/vazio ("o presente termo...", "fica...") = fraco. */
    private static function ehFraco(string $s): bool
    {
        return (bool) preg_match('/^(?:[oa]\s+presente|este|esta|fic[ao]m?|constitui|conforme|nos\s+termos)\b/iu', trim($s));
    }

    /** Remove o cabeçalho institucional do começo (iterativo, só do início). */
    private static function tiraBoiler(string $t): string
    {
        $padroes = [
            '/^p[áa]gina[:\s].*?(?=\b[A-ZÀ-Ú]{2,})/u',
            '/^estado\s+de\s+santa\s+catarina\b/iu',
            '/^(?:prefeitura\s+municipal|munic[íi]pio|c[âa]mara\s+municipal|fundo\s+municipal[^.]*?|poder\s+executivo|servi[çc]o\s+[^.]*?)\s+de\s+[A-ZÀ-Ú][^,.\d]{1,40}?(?=\b(?:cnpj|rua|av|avenida|processo|dispensa|preg[ãa]o|inexigibilidade|extrato|portaria|edital|torna|objeto)\b)/iu',
            '/^(?:rua|av\.?|avenida|pra[çc]a)\b[^\n]*?cep[:\s]*\d{5}-?\d{3}/iu',
            '/^cnpj[:\s][\d.\/-]+/iu',
            '/^telefone[:\s][^\n]{0,40}/iu',
            '/^(?:processo\s+(?:administrativo|licitat[óo]rio)?|dispensa(?:\s+eletr[ôo]nica)?|preg[ãa]o(?:\s+(?:eletr[ôo]nico|presencial))?|inexigibilidade|tomada\s+de\s+pre[çc]os?|concorr[êe]ncia|edital|portaria|extrato(?:\s+d[oe])?)\s*(?:de\s+licita[çc][ãa]o)?\s*n?[º°.\s-]*\s*[\d\/.-]+/iu',
            '/^[\s\-–.:]+/u',
        ];
        $ant = null;
        $i = 0;
        while ($ant !== $t && $i++ < 12) {
            $ant = $t;
            foreach ($padroes as $p) {
                $t = preg_replace($p, '', $t, 1, $n) ?? $t;
                if ($n) {
                    $t = ltrim($t, " \t\n\r-–.:");
                }
            }
        }

        return trim($t);
    }

    private static function fallbackTitulo(?string $titulo): ?string
    {
        $t = self::normEspaco((string) $titulo);

        return $t !== '' ? self::finalizar($t) : null;
    }

    // ───────────────────────── normalização ─────────────────────────

    private static function normEspaco(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Limpa, normaliza caixa de objeto TODO-MAIÚSCULO e limita tamanho. */
    private static function finalizar(string $s): string
    {
        $s = self::normEspaco($s);
        // tira pontuação/aspas (inclui aspas curvas “ ” ‘ ’) das pontas
        $s = preg_replace('/^[\s\-–.:;,"\x{201C}\x{201D}\x{2018}\x{2019}\']+|[\s\-–.:;,"\x{201C}\x{201D}\x{2018}\x{2019}\']+$/u', '', $s);
        if ($s === '') {
            return '';
        }
        // Atos costumam vir em CAIXA ALTA — vira sentence-case (mais legível) só
        // se ≥70% das letras forem maiúsculas; senão preserva (mantém siglas).
        $letras = (string) preg_replace('/[^\p{L}]/u', '', $s);
        $maiusc = (string) preg_replace('/[^\p{Lu}]/u', '', $s);
        if ($letras !== '' && mb_strlen($maiusc) / mb_strlen($letras) >= 0.7) {
            $s = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            // pequenas palavras voltam pra minúsculo
            $s = preg_replace_callback('/\b(De|Da|Do|Das|Dos|E|Em|Para|Com|A|O|No|Na|Nos|Nas|Por)\b/u',
                fn ($m) => mb_strtolower($m[1], 'UTF-8'), $s);
            $s = mb_strtoupper(mb_substr($s, 0, 1), 'UTF-8') . mb_substr($s, 1);
        }

        return mb_strimwidth($s, 0, 240, '…');
    }
}
