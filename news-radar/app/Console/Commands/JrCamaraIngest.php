<?php

namespace App\Console\Commands;

use App\Services\Jr\SaplConector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico FASE 2 — ingere proposições das câmaras (SAPL) em
 * jr_camara_proposicoes. ADITIVO e ISOLADO (não toca DOM/juiz/captura).
 *
 * FORWARD (default): varre o ano corrente, recente-primeiro, e PARA de paginar
 * ao bater em N proposições já vistas consecutivas (--parar-vistos) — barato,
 * grade /5. BACKFILL (--backfill): varre todo o histórico de cada câmara (sem
 * early-stop) até o teto de páginas — roda manual/baixa prioridade.
 *
 * Dedup idempotente por hash(host:materia_id). Só ingere tipos lei-making.
 */
class JrCamaraIngest extends Command
{
    protected $signature = 'jr:camara-ingest '
        . '{--backfill : Varre TODO o histórico (sem early-stop), não só o ano corrente} '
        . '{--ano= : Restringe a um ano específico} '
        . '{--cidade= : Só esta câmara (casa pelo nome, ex.: "Rio do Sul")} '
        . '{--max-paginas= : Teto de páginas por câmara (default config)} '
        . '{--parar-vistos=20 : FORWARD: para após N vistos consecutivos (0 = não para)}';

    protected $description = 'Ingere proposições das câmaras (SAPL) — forward incremental ou backfill histórico.';

    public function handle(SaplConector $conector): int
    {
        $cfg = config('camara');
        $maxPaginas = (int) ($this->option('max-paginas') ?: $cfg['max_paginas']);
        $pararVistos = (int) $this->option('parar-vistos');
        $tiposAlvo = $cfg['tipos_relevantes'];
        $backfill = (bool) $this->option('backfill');
        $anoOpt = $this->option('ano');
        $cidadeFiltro = trim((string) $this->option('cidade'));

        // FORWARD: ano corrente; BACKFILL: todos. --ano sobrepõe.
        $ano = $anoOpt !== null && $anoOpt !== '' ? (int) $anoOpt : ($backfill ? null : (int) now()->year);
        if ($backfill) {
            $pararVistos = 0; // backfill não faz early-stop
        }

        $camaras = collect($cfg['camaras']);
        if ($cidadeFiltro !== '') {
            $camaras = $camaras->filter(fn ($c) => mb_stripos($c['cidade'], $cidadeFiltro) !== false);
        }
        if ($camaras->isEmpty()) {
            $this->error('Nenhuma câmara casou o filtro.');

            return self::FAILURE;
        }

        $this->info(sprintf('%s · %d câmara(s) · ano=%s · max_pag=%d',
            $backfill ? 'BACKFILL' : 'FORWARD', $camaras->count(), $ano ?? 'todos', $maxPaginas));

        $totNovos = 0;
        $totVistos = 0;
        foreach ($camaras as $cam) {
            $host = $cam['host'];
            $municipio = $cam['cidade'];
            $novos = 0;
            $vistos = 0;
            // jaVisto: o generator usa pra early-stop POR TIPO (forward). Conta os
            // vistos aqui também (pro relatório). Só itens NOVOS são yielded.
            $jaVisto = function (array $p) use ($host, &$vistos) {
                $existe = DB::table('jr_camara_proposicoes')->where('hash', sha1($host . ':' . $p['materia_id']))->exists();
                if ($existe) {
                    $vistos++;
                }

                return $existe;
            };
            try {
                foreach ($conector->materias($host, $municipio, $ano, $maxPaginas, $tiposAlvo, $jaVisto, $pararVistos) as $p) {
                    $hash = sha1($host . ':' . $p['materia_id']);
                    DB::table('jr_camara_proposicoes')->insert([
                        'source' => 'camara',
                        'host' => $host,
                        'materia_id' => $p['materia_id'],
                        'hash' => $hash,
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
                $this->warn("  {$municipio}: erro — " . mb_substr($e->getMessage(), 0, 140));
            }
            $this->line(sprintf('  %-18s %3d novos · %3d vistos', $municipio, $novos, $vistos));
            $totNovos += $novos;
            $totVistos += $vistos;
        }

        $this->info(sprintf('Total: %d novos · %d vistos.', $totNovos, $totVistos));

        return self::SUCCESS;
    }
}
