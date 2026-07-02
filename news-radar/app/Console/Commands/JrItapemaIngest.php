<?php

namespace App\Console\Commands;

use App\Services\Jr\ItapemaConector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico — ingere PROJETOS da Câmara de Itapema (elegis2) em
 * jr_camara_proposicoes. ADITIVO e ISOLADO. O CamaraScorer pontua sem mudança.
 *
 * Forward-first com early-stop: para de paginar quando a página só tem itens já
 * vistos ou de ano < ano_min (default 2025 — backfill leve). Pra cada item NOVO
 * faz 2 GETs extras (detalhe = data de criação; PDF = ementa via PyMuPDF), com
 * >=1s entre requests — barato no forward (poucos novos/dia).
 */
class JrItapemaIngest extends Command
{
    protected $signature = 'jr:itapema-ingest '
        . '{--ano-min= : Sobrepõe o piso de ano da config} '
        . '{--max-paginas= : Teto de páginas (default config)} '
        . '{--parar-vistos=15 : Para após N itens já vistos consecutivos (0 = não para)}';

    protected $description = 'Ingere projetos da Câmara de Itapema (elegis2) — dedup por hash, ementa do PDF.';

    public function handle(ItapemaConector $conector): int
    {
        $cfg = config('camara.itapema');
        $anoMin = (int) ($this->option('ano-min') ?: $cfg['ano_min']);
        $maxPag = (int) ($this->option('max-paginas') ?: $cfg['max_paginas']);
        $pararVistos = (int) $this->option('parar-vistos');

        $this->info("ITAPEMA (elegis2) · ano>={$anoMin} · max_pag={$maxPag}");

        $novos = 0;
        $vistos = 0;
        $seguidos = 0;
        $fim = false;
        for ($pag = 1; $pag <= $maxPag && ! $fim; $pag++) {
            if ($pag > 1) {
                $conector->dorme();
            }
            try {
                $itens = $conector->listar($pag);
            } catch (\Throwable $e) {
                $this->warn("  p{$pag}: erro — " . mb_substr($e->getMessage(), 0, 120));
                break;
            }
            if (! $itens) {
                break;
            }
            foreach ($itens as $it) {
                if ($it['ano'] < $anoMin) {
                    $fim = true; // listagem é recente-primeiro; dali pra baixo é histórico

                    break;
                }
                $hash = sha1('itapema:' . $it['cod']);
                if (DB::table('jr_camara_proposicoes')->where('hash', $hash)->exists()) {
                    $vistos++;
                    if ($pararVistos > 0 && ++$seguidos >= $pararVistos) {
                        $fim = true;

                        break;
                    }

                    continue;
                }
                $seguidos = 0;

                $conector->dorme();
                $dataPub = $conector->dataCriacao($it['cod']);
                $conector->dorme();
                [$ementa, $texto, $urlPdf] = $conector->ementaDoPdf($it['cod']);

                DB::table('jr_camara_proposicoes')->insert([
                    'source' => 'camara',
                    'host' => 'site.itapema.sc.leg.br',
                    'materia_id' => $it['cod'],
                    'hash' => $hash,
                    'municipio' => 'Itapema',
                    'orgao' => 'Câmara Municipal de Itapema',
                    'tipo_sigla' => null,
                    'tipo_descricao' => $it['tipo'],
                    'complementar' => (bool) preg_match('/complementar/iu', $it['tipo']),
                    'numero' => $it['numero'],
                    'ano' => $it['ano'],
                    'ementa' => $ementa,
                    'autores' => $it['autor'],
                    'em_tramitacao' => ! preg_match('/arquivad|sancionad|promulgad/iu', (string) $it['situacao']),
                    'data_pub' => $dataPub,
                    'titulo' => $it['titulo'],
                    'url_fonte' => config('camara.itapema.base') . '/elegis2/detalhe-proposicao/cod_proposicao/' . $it['cod'],
                    'url_pdf' => $urlPdf,
                    'texto_bruto' => trim(($texto ?: '') . ($it['situacao'] ? "\n[situação] {$it['situacao']}" : '')) ?: null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $novos++;
            }
        }

        $this->info(sprintf('Itapema: %d novos · %d vistos.', $novos, $vistos));

        return self::SUCCESS;
    }
}
