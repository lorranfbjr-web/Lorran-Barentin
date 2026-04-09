<?php

namespace App\Modules\NewsRadar\Services;

use Carbon\Carbon;

class DateParserService
{
    private const PORTUGUESE_MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3,
        'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7,
        'agosto' => 8, 'setembro' => 9, 'outubro' => 10,
        'novembro' => 11, 'dezembro' => 12,
        'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4,
        'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
        'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
    ];

    public function parse(?string $raw, string $timezone = 'America/Sao_Paulo', array $customFormats = []): ?Carbon
    {
        if (empty($raw)) return null;

        $raw = trim($raw);

        // Try ISO 8601 / standard formats first
        try {
            return Carbon::parse($raw)->setTimezone($timezone);
        } catch (\Exception) {}

        // Preprocess: Portuguese month names -> numbers
        $processed = $this->preprocessPortuguese($raw);
        if ($processed !== $raw) {
            try {
                return Carbon::parse($processed)->setTimezone($timezone);
            } catch (\Exception) {}
        }

        // Try custom formats from source config
        foreach ($customFormats as $format) {
            try {
                return Carbon::createFromFormat($format, $raw, $timezone);
            } catch (\Exception) {
                continue;
            }
        }

        // Try common Brazilian formats
        $formats = [
            'd/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y',
            'd-m-Y H:i', 'd.m.Y H:i', 'd.m.Y',
        ];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $raw, $timezone);
            } catch (\Exception) {
                continue;
            }
        }

        // "Hoje às 14h" / "Hoje, 14:30"
        if (preg_match('/hoje.*?(\d{1,2})[h:](\d{2})?/i', $raw, $m)) {
            return Carbon::today($timezone)->setTime((int)$m[1], (int)($m[2] ?? 0));
        }

        // "Ontem às 14h"
        if (preg_match('/ontem.*?(\d{1,2})[h:](\d{2})?/i', $raw, $m)) {
            return Carbon::yesterday($timezone)->setTime((int)$m[1], (int)($m[2] ?? 0));
        }

        // "há X horas/minutos"
        if (preg_match('/h[áa]\s+(\d+)\s+(hora|minuto|min)/i', $raw, $m)) {
            $amount = (int)$m[1];
            $unit = str_starts_with(strtolower($m[2]), 'hora') ? 'hours' : 'minutes';
            return Carbon::now($timezone)->sub($unit, $amount);
        }

        return null;
    }

    private function preprocessPortuguese(string $raw): string
    {
        $lower = mb_strtolower($raw);

        // Remove day-of-week prefixes
        $lower = preg_replace('/^(segunda|ter[çc]a|quarta|quinta|sexta|s[áa]bado|domingo)[\s,-]*/u', '', $lower);

        // Replace "de" connectors: "10 de janeiro de 2026"
        $lower = preg_replace('/\s+de\s+/u', ' ', $lower);

        foreach (self::PORTUGUESE_MONTHS as $name => $number) {
            if (str_contains($lower, $name)) {
                $lower = str_replace($name, str_pad((string)$number, 2, '0', STR_PAD_LEFT), $lower);
            }
        }

        return $lower;
    }
}
