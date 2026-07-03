<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;

/**
 * BLOCO 0b (03/07): janela de silêncio do canal de WhatsApp interno — dentro
 * dela NENHUM comando do radar acorda o grupo (alerta, auto-rascunho, kit).
 * Faixa em hora LOCAL ("HH-HH"; "22-06" cruza a meia-noite). Faixa inválida,
 * vazia ou nula (ex.: "8-8") = sem silêncio — alerta é o produto, fail-open.
 */
final class JanelaSilencio
{
    /**
     * @param array $cfg espera as chaves 'silencio' (ex.: "22-06") e
     *                   'tz_local' (default America/Sao_Paulo) — o shape de
     *                   config('radar_civico.alertas').
     */
    public static function ativa(array $cfg): bool
    {
        $faixa = trim((string) ($cfg['silencio'] ?? ''));
        if ($faixa === '' || ! preg_match('/^(\d{1,2})-(\d{1,2})$/', $faixa, $m)) {
            return false;
        }
        $ini = min(23, (int) $m[1]);
        $fim = min(23, (int) $m[2]);
        if ($ini === $fim) {
            return false;
        }
        $h = (int) Carbon::now((string) ($cfg['tz_local'] ?? 'America/Sao_Paulo'))->format('G');

        return $ini < $fim ? ($h >= $ini && $h < $fim) : ($h >= $ini || $h < $fim);
    }
}
