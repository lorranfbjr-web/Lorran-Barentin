<?php

namespace App\Console\Commands;

use App\Services\Jr\LegisladorConector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico — ingere projetos das câmaras no LEGISLADOR WEB (legislador.com.br,
 * 7 cidades de interesse, sonda 02/07/2026) em jr_camara_proposicoes. ADITIVO e
 * ISOLADO (não toca SAPL/DOM/juiz). O CamaraScorer existente pontua sem mudança.
 *
 * 1 GET por câmara (a página ProjetoTramite traz tudo em tramitação); dedup
 * idempotente por hash. Forward-first: só ano >= camara.legislador.ano_min
 * (default 2025 — backfill leve). Educado: >=1s entre câmaras.
 */
class JrLegisladorIngest extends Command
{
    protected $signature = 'jr:legislador-ingest '
        . '{--cidade= : Só esta câmara (casa pelo nome, ex.: "Penha")} '
        . '{--ano-min= : Sobrepõe o piso de ano da config}';

    protected $description = 'Ingere projetos das câmaras no Legislador WEB (7 cidades de interesse) — dedup por hash.';

    public function handle(LegisladorConector $conector): int
    {
        $cfg = config('camara.legislador');
        $anoMin = (int) ($this->option('ano-min') ?: $cfg['ano_min']);
        $cidadeFiltro = trim((string) $this->option('cidade'));

        $cidades = collect($cfg['cidades']);
        if ($cidadeFiltro !== '') {
            $cidades = $cidades->filter(fn ($c) => mb_stripos($c['cidade'], $cidadeFiltro) !== false);
        }
        if ($cidades->isEmpty()) {
            $this->error('Nenhuma câmara casou o filtro.');

            return self::FAILURE;
        }

        $this->info(sprintf('LEGISLADOR · %d câmara(s) · ano>=%d', $cidades->count(), $anoMin));

        $totNovos = 0;
        $totVistos = 0;
        $primeira = true;
        foreach ($cidades as $cam) {
            if (! $primeira) {
                $conector->dorme(); // politeness entre câmaras (mesmo host)
            }
            $primeira = false;
            $novos = 0;
            $vistos = 0;
            try {
                foreach ($conector->projetos((int) $cam['id'], $cam['cidade'], $anoMin) as $p) {
                    if (DB::table('jr_camara_proposicoes')->where('hash', $p['hash'])->exists()) {
                        $vistos++;

                        continue;
                    }
                    DB::table('jr_camara_proposicoes')->insert([
                        'source' => 'camara',
                        'host' => $p['host'],
                        'materia_id' => $p['materia_id'],
                        'hash' => $p['hash'],
                        'municipio' => $p['municipio'],
                        'orgao' => $p['orgao'],
                        'tipo_sigla' => $p['tipo_sigla'],
                        'tipo_descricao' => $p['tipo_descricao'],
                        'complementar' => $p['complementar'],
                        'numero' => $p['numero'],
                        'ano' => $p['ano'],
                        'ementa' => $p['ementa'],
                        'autores' => $p['autores'],
                        'em_tramitacao' => $p['em_tramitacao'],
                        'data_pub' => $p['data_pub'],
                        'titulo' => $p['titulo'],
                        'url_fonte' => $p['url_fonte'],
                        'url_pdf' => $p['url_pdf'],
                        'texto_bruto' => $p['texto_bruto'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $novos++;
                }
            } catch (\Throwable $e) {
                $this->warn("  {$cam['cidade']}: erro — " . mb_substr($e->getMessage(), 0, 140));
            }
            $this->line(sprintf('  %-18s %3d novos · %3d vistos', $cam['cidade'], $novos, $vistos));
            $totNovos += $novos;
            $totVistos += $vistos;
        }

        $this->info(sprintf('Total: %d novos · %d vistos.', $totNovos, $totVistos));

        return self::SUCCESS;
    }
}
