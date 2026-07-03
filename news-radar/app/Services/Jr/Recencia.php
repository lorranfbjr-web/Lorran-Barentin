<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;

/**
 * VERDADE ÚNICA DE RECÊNCIA do Radar Cívico (Goal 02/07, BLOCO 1).
 *
 * Motivo: o alerta janelava por `scored_at` e o backfill das câmaras (itens de
 * 2013–2025 + uma data corrompida 2028) alertou como se fosse quente. Agora
 * TUDO que fala "fresco/recente" — exibição (RankingExibicao), alerta
 * (jrcivico:alertar) e ingest — passa por aqui:
 *
 *  - sanitizar(): normaliza data_pub pra ISO YYYY-MM-DD no INGEST; data futura
 *    (> hoje+2d) ou implausível (< 2015) NÃO entra no banco — vira NULL +
 *    flag data_suspeita=1 (coluna aditiva). Nunca grava lixo.
 *  - fresco(): "quente DE VERDADE" = data_pub nos últimos N dias E não-futura.
 *
 * Item sem data_pub confiável não alerta (só aparece na página, seção Arquivo).
 */
class Recencia
{
    /** Data mais antiga plausível pro radar (antes disso = suspeita). */
    public const MIN_PLAUSIVEL = '2015-01-01';

    /** Tolerância de futuro (edições do DOM saem com data de amanhã). */
    public const FUTURO_TOLERANCIA_DIAS = 2;

    /**
     * Normaliza pra ISO e rejeita data furada. Aceita 'YYYY-MM-DD…' e
     * 'dd/mm/aaaa'. @return array{data_pub: ?string, data_suspeita: ?int}
     */
    public static function sanitizar(?string $data): array
    {
        $s = trim((string) $data);
        if ($s === '') {
            return ['data_pub' => null, 'data_suspeita' => null]; // sem data ≠ data errada
        }

        $iso = null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            $iso = "{$m[1]}-{$m[2]}-{$m[3]}";
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
            $iso = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // não parseou ou não é data de calendário real → suspeita, não grava lixo
        if ($iso === null || ! checkdate((int) substr($iso, 5, 2), (int) substr($iso, 8, 2), (int) substr($iso, 0, 4))) {
            return ['data_pub' => null, 'data_suspeita' => 1];
        }

        $max = Carbon::now()->addDays(self::FUTURO_TOLERANCIA_DIAS)->toDateString();
        if ($iso > $max || $iso < self::MIN_PLAUSIVEL) {
            return ['data_pub' => null, 'data_suspeita' => 1];
        }

        return ['data_pub' => $iso, 'data_suspeita' => null];
    }

    /** Fresco = publicado nos últimos $dias E não no futuro. Mesma régua pra exibição e alerta. */
    public static function fresco(?string $dataPub, int $dias): bool
    {
        if (! $dataPub) {
            return false;
        }
        $hoje = Carbon::now()->toDateString();

        return $dataPub >= Carbon::now()->subDays(max(1, $dias))->toDateString()
            && $dataPub <= $hoje;
    }
}
