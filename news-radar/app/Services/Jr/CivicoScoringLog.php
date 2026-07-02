<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B5 (02/07/2026) — registro por ciclo do scoring cívico em
 * jr_civico_scoring_log (fonte, chamadas, scorados, falhas, fila restante).
 * NUNCA lança: observabilidade não pode derrubar o faro.
 */
class CivicoScoringLog
{
    public static function registrar(string $fonte, array $d): void
    {
        try {
            DB::table('jr_civico_scoring_log')->insert([
                'fonte' => $fonte,
                'chamadas' => (int) ($d['chamadas'] ?? 0),
                'scorados' => (int) ($d['scorados'] ?? 0),
                'falhas' => (int) ($d['falhas'] ?? 0),
                'pendentes_apos' => (int) ($d['pendentes_apos'] ?? 0),
                'modelo' => $d['modelo'] ?? null,
                'duracao_ms' => (int) ($d['duracao_ms'] ?? 0),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CivicoScoringLog] falhou (ignorado): ' . $e->getMessage());
        }
    }
}
