<?php

namespace App\Services\Jr;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolve os MEMBROS (portais) de um assunto do radar. Fonte única usada tanto
 * pelo container (JrReescritaController) quanto pela entrega assíncrona
 * (jrpauta:entregar). 'a...' = assunto_id; 'i<id>' = item solto (singleton).
 */
class RadarAssunto
{
    /** @return Collection<int,object> linhas com texto/host/url do assunto. */
    public static function membros(string $assuntoId): Collection
    {
        $cols = ['id', 'cluster_id', 'host', 'fonte_tipo', 'url', 'titulo', 'markdown', 'char_len', 'cidade_llm', 'score'];

        if (str_starts_with($assuntoId, 'i')) {
            $id = (int) substr($assuntoId, 1);

            return DB::table('jr_link_extracao')->where('id', $id)->get($cols);
        }

        $clusterIds = DB::table('jr_link_extracao')->where('assunto_id', $assuntoId)
            ->whereNotNull('cluster_id')->distinct()->pluck('cluster_id');
        if ($clusterIds->isEmpty()) {
            return collect();
        }

        return DB::table('jr_link_extracao')->whereIn('cluster_id', $clusterIds)
            ->where('duplicada', false)
            ->orderByDesc('score')
            ->get($cols)
            ->unique('url')->values();
    }
}
