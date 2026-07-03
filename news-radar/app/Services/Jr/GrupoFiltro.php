<?php

namespace App\Services\Jr;

/**
 * GOAL SIMPLIFICAR (03/07) — BLOCO 2: filtro ANTI-GARBAGE de GRUPO.
 *
 * Regra dura, config-driven (config/radar_civico.php 'grupo_filtro'): grupo
 * de WhatsApp só recebe o que um editor de Tijucas/região quereria ver — o
 * resto continua 100% no site (/dom, /justica…), na Mesa e no score do banco.
 * NADA aqui muda score; só decide ENVIA/NÃO-ENVIA pra grupo.
 *
 * Validado na auditoria de 03/07 (110 últimos envios): corte de 65% do volume
 * mantendo todos os itens de interesse da região (ver RELATORIO-simplificar).
 *
 *  · cidade fora do interesse (tier 0)  → NUNCA em grupo
 *  · objeto vago                        → NUNCA em grupo
 *  · objeto ROTINEIRO (show contratado por inexigibilidade, licença de
 *    software, termo aditivo/cláusula)  → NUNCA em grupo
 *  · núcleo (cidades_prioritarias)      → score >= nucleo_min (default 70)
 *  · fiscalização tier1 >= fisc_t1 (80) · tier2 >= fisc_t2 (85)
 *  · serviço fora do núcleo             → só site (auto-rascunho já entrega
 *    os melhores releases como rascunho pronto; alerta de serviço de cidade
 *    distante é ruído)
 *  · notícia (quente-fria) tier1 >= noticia_t1 (80) · tier2 >= noticia_t2 (85)
 */
class GrupoFiltro
{
    /** Objeto de ROTINA administrativa — nunca vira mensagem de grupo. */
    private const PADROES_ROTINA = [
        '/apresenta[cç][aã]o (art[ií]stica|d[oa] (artista|dupla|banda|cantor))/iu',
        '/contrata[cç][aã]o d[oa] (artista|dupla|banda|cantor|show)/iu',
        '/licen[cç]a de uso de (solu[cç][aã]o|software|sistema)/iu',
        '/(altera[cç][aã]o|inclus[aã]o) de (dispositivo|cl[aá]usula)/iu',
        '/\btermo aditivo\b/iu',
    ];

    /** @return array{ok:bool, motivo:string} decisão pro alerta CÍVICO. */
    public static function civico(string $municipio, int $score, ?string $tipo, ?string $objetoLimpo): array
    {
        $cfg = (array) config('radar_civico.grupo_filtro', []);
        if (! ($cfg['ligado'] ?? true)) {
            return ['ok' => true, 'motivo' => 'filtro desligado'];
        }

        if (RankingExibicao::vago($objetoLimpo)) {
            return ['ok' => false, 'motivo' => 'objeto vago'];
        }
        foreach (self::PADROES_ROTINA as $rx) {
            if (preg_match($rx, (string) $objetoLimpo)) {
                return ['ok' => false, 'motivo' => 'objeto rotineiro'];
            }
        }

        $tier = CidadesInteresse::tier($municipio);
        if ($tier === 0) {
            return ['ok' => false, 'motivo' => 'fora do interesse (tier0)'];
        }

        if (self::nucleo($municipio)) {
            $min = (int) ($cfg['nucleo_min'] ?? 70);

            return $score >= $min
                ? ['ok' => true, 'motivo' => 'núcleo']
                : ['ok' => false, 'motivo' => "núcleo score {$score} < {$min}"];
        }

        if ($tipo === 'fiscalizacao') {
            $min = $tier === 1 ? (int) ($cfg['fisc_t1'] ?? 80) : (int) ($cfg['fisc_t2'] ?? 85);

            return $score >= $min
                ? ['ok' => true, 'motivo' => "fisc t{$tier}"]
                : ['ok' => false, 'motivo' => "fisc t{$tier} score {$score} < {$min}"];
        }

        // serviço/institucional fora do núcleo: só site + auto-rascunho
        return ['ok' => false, 'motivo' => 'serviço fora do núcleo'];
    }

    /** @return array{ok:bool, motivo:string} decisão pra NOTÍCIA (quente-fria). */
    public static function noticia(string $cidade, int $scoreComposto): array
    {
        $cfg = (array) config('radar_civico.grupo_filtro', []);
        if (! ($cfg['ligado'] ?? true)) {
            return ['ok' => true, 'motivo' => 'filtro desligado'];
        }

        $tier = CidadesInteresse::tier($cidade);
        if ($tier === 0) {
            return ['ok' => false, 'motivo' => 'fora do interesse (tier0)'];
        }
        if (self::nucleo($cidade)) {
            $min = (int) ($cfg['nucleo_min'] ?? 70);

            return $scoreComposto >= $min
                ? ['ok' => true, 'motivo' => 'núcleo']
                : ['ok' => false, 'motivo' => "núcleo score {$scoreComposto} < {$min}"];
        }
        $min = $tier === 1 ? (int) ($cfg['noticia_t1'] ?? 80) : (int) ($cfg['noticia_t2'] ?? 85);

        return $scoreComposto >= $min
            ? ['ok' => true, 'motivo' => "notícia t{$tier}"]
            : ['ok' => false, 'motivo' => "notícia t{$tier} score {$scoreComposto} < {$min}"];
    }

    /** Núcleo de cobertura = cidades_prioritarias (RADAR_CIVICO_CIDADES). */
    private static function nucleo(?string $cidade): bool
    {
        $nucleo = (array) config('radar_civico.alertas.cidades_prioritarias', []);
        $n = DomGeografia::normalizar((string) $cidade);
        foreach ($nucleo as $c) {
            if (DomGeografia::normalizar($c) === $n) {
                return true;
            }
        }

        return false;
    }
}
