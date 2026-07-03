<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Log;

/**
 * Tier de interesse editorial por município (config/interesse.php). Lookup
 * normalizado (reusa DomGeografia::normalizar — MESMA régua nas 4 tabelas do
 * Radar Cívico). Estático+memoizado, padrão DomGeografia.
 *
 * Só EXIBIÇÃO: quem consome é RankingExibicao/controllers; nada aqui escreve
 * no banco nem toca score_pauta.
 */
class CidadesInteresse
{
    /** @var array<string,int>|null nomeNormalizado => tier(1|2) */
    private static ?array $indice = null;

    /** @var array<string,bool>|null nomeNormalizado => true (anunciante ativo) */
    private static ?array $indiceAnun = null;

    /** 1 = núcleo/cobertura, 2 = vizinha, 0 = fora do interesse. */
    public static function tier(?string $municipio): int
    {
        if (! $municipio) {
            return 0;
        }

        return self::indice()[DomGeografia::normalizar($municipio)] ?? 0;
    }

    /**
     * BLOCO 6 (03/07): cidade com ANUNCIANTE ATIVO (config interesse.anunciantes,
     * env JR_ANUNCIANTES_CIDADES). SÓ EXIBIÇÃO — badge 💰 + filtro no hub;
     * NÃO pesa score algum (nem o de exibição).
     */
    public static function anunciante(?string $municipio): bool
    {
        if (! $municipio) {
            return false;
        }

        if (self::$indiceAnun === null) {
            $idx = [];
            foreach ((array) config('interesse.anunciantes') as $m) {
                $idx[DomGeografia::normalizar($m)] = true;
            }
            self::$indiceAnun = $idx;
        }

        return self::$indiceAnun[DomGeografia::normalizar($municipio)] ?? false;
    }

    public static function bonus(int $tier): int
    {
        return match ($tier) {
            1 => (int) config('interesse.pesos.tier1', 12),
            2 => (int) config('interesse.pesos.tier2', 6),
            default => 0,
        };
    }

    /** Grafias OFICIAIS dos tiers (pro whereIn SQL da perna 'interesse'). */
    public static function nomesOficiais(): array
    {
        return array_merge((array) config('interesse.tier1'), (array) config('interesse.tier2'));
    }

    /** Frase curta pros PROMPTS dos scorers (itens NOVOS). */
    public static function listaPrompt(): string
    {
        return implode(', ', (array) config('interesse.tier1'))
            . ' (núcleo); ' . implode(', ', (array) config('interesse.tier2')) . ' (região)';
    }

    private static function indice(): array
    {
        if (self::$indice !== null) {
            return self::$indice;
        }
        $idx = [];
        $oficiais = array_map(DomGeografia::normalizar(...), DomGeografia::municipios());
        foreach ([2 => (array) config('interesse.tier2'), 1 => (array) config('interesse.tier1')] as $tier => $lista) {
            foreach ($lista as $m) {
                $k = DomGeografia::normalizar($m);
                if (! in_array($k, $oficiais, true)) {
                    Log::warning("interesse.php: município desconhecido '{$m}' — confira a grafia");
                }
                $idx[$k] = $tier; // tier1 sobrescreve tier2 (loop 2→1)
            }
        }

        return self::$indice = $idx;
    }
}
