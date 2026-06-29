<?php

namespace App\Services\Jr;

use App\Console\Commands\JrDomEntidades;

/**
 * Lê o registro estático de entidades do DOM/SC (gerado por `jr:dom-entidades`)
 * e agrupa por município pra alimentar os selects da /dom-busca.
 *
 * Cada entidade tem um codigoEntidade — o filtro estruturado de entidade do DOM
 * (via feed RSS por entidade). Selecionar a entidade exata elimina o "Itajaí
 * genérico" (consórcios cujo nome contém o nome do rio/região).
 */
class DomEntidades
{
    /** @var array<int,array>|null */
    private static ?array $cache = null;

    /** @return array<int,array> todas as entidades [codigo,nome,tipo,municipio,regiao] */
    public static function todas(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $caminho = base_path(JrDomEntidades::CAMINHO);
        if (! is_file($caminho)) {
            return self::$cache = [];
        }
        $json = json_decode((string) file_get_contents($caminho), true);

        return self::$cache = $json['entidades'] ?? [];
    }

    /** Municípios que têm entidade, ordenados. */
    public static function municipios(): array
    {
        $m = [];
        foreach (self::todas() as $e) {
            if ($e['municipio']) {
                $m[$e['municipio']] = true;
            }
        }
        $out = array_keys($m);
        sort($out);

        return $out;
    }

    /** Entidades de um município (Prefeitura/Câmara/Fundo…), ordenadas por tipo. */
    public static function porMunicipio(string $municipio): array
    {
        $ordem = ['Prefeitura' => 0, 'Câmara' => 1, 'Fundo' => 2, 'Instituto/Previdência' => 3, 'Autarquia/Serviço' => 4, 'Convênio' => 5];
        $out = array_values(array_filter(self::todas(), fn ($e) => $e['municipio'] === $municipio));
        usort($out, fn ($a, $b) => ($ordem[$a['tipo']] ?? 9) <=> ($ordem[$b['tipo']] ?? 9) ?: strcmp($a['nome'], $b['nome']));

        return $out;
    }

    /** Entidades regionais (consórcios/associações sem município). */
    public static function regionais(): array
    {
        $out = array_values(array_filter(self::todas(), fn ($e) => $e['municipio'] === null));
        usort($out, fn ($a, $b) => strcmp($a['nome'], $b['nome']));

        return $out;
    }

    public static function find(int $codigo): ?array
    {
        foreach (self::todas() as $e) {
            if ((int) $e['codigo'] === $codigo) {
                return $e;
            }
        }

        return null;
    }
}
