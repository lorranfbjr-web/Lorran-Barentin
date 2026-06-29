<?php

namespace App\Console\Commands;

use App\Services\Jr\TceConector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico FASE 5 — ingere os processos/decisões do DOTC-e (TCE-SC) em
 * jr_tce_decisoes. ADITIVO e ISOLADO. Forward-first: últimos N dias úteis
 * (Seg-Sex; fim de semana = 404, pula). Dedup idempotente por hash(processo).
 */
class JrTceIngest extends Command
{
    protected $signature = 'jr:tce-ingest '
        . '{--dias= : Dias úteis pra trás (default config tce.dias_uteis)} '
        . '{--data= : Ingere só uma data específica (AAAA-MM-DD)}';

    protected $description = 'Ingere processos/decisões do TCE-SC (DOTC-e diário, PDF).';

    public function handle(TceConector $conector): int
    {
        $datas = [];
        if ($this->option('data')) {
            $datas[] = Carbon::parse((string) $this->option('data'));
        } else {
            $dias = (int) ($this->option('dias') ?: config('tce.dias_uteis'));
            $d = Carbon::now();
            while (count($datas) < $dias) {
                if (! $d->isWeekend()) {
                    $datas[] = $d->copy();
                }
                $d->subDay();
            }
        }

        $totNovos = 0;
        $totVistos = 0;
        foreach ($datas as $data) {
            $decisoes = $conector->decisoesDaData($data);
            $novos = 0;
            $vistos = 0;
            foreach ($decisoes as $e) {
                if (DB::table('jr_tce_decisoes')->where('hash', $e['hash'])->exists()) {
                    $vistos++;

                    continue;
                }
                DB::table('jr_tce_decisoes')->insert([
                    'source' => 'tce',
                    'hash' => $e['hash'],
                    'processo' => $e['processo'],
                    'tipo_proc' => $e['tipo_proc'],
                    'assunto' => $e['assunto'],
                    'interessado' => $e['interessado'],
                    'responsavel' => $e['responsavel'],
                    'unidade_gestora' => $e['unidade_gestora'],
                    'municipio' => $e['municipio'],
                    'relator' => $e['relator'],
                    'decisao' => $e['decisao'],
                    'desfecho' => $e['desfecho'],
                    'data_pub' => $e['data_pub'],
                    'edicao' => $e['edicao'],
                    'url_fonte' => $e['url_fonte'],
                    'texto_bruto' => $e['texto_bruto'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $novos++;
            }
            $this->line(sprintf('  %s  %3d novos · %3d vistos · %d processos', $data->toDateString(), $novos, $vistos, count($decisoes)));
            $totNovos += $novos;
            $totVistos += $vistos;
        }

        $this->info(sprintf('Total: %d novos · %d vistos.', $totNovos, $totVistos));

        return self::SUCCESS;
    }
}
