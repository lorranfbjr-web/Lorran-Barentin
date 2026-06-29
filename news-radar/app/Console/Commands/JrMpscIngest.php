<?php

namespace App\Console\Commands;

use App\Services\Jr\MpscConector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico FASE 4 — ingere os EXTRATOS DE INSTAURAÇÃO do DOE-MPSC em
 * jr_mpsc_extratos. ADITIVO e ISOLADO. Forward-first: varre os últimos N dias
 * ÚTEIS (Seg-Sex; fim de semana não tem edição → 404, pula). Dedup idempotente
 * por hash estável do extrato.
 */
class JrMpscIngest extends Command
{
    protected $signature = 'jr:mpsc-ingest '
        . '{--dias= : Dias úteis pra trás (default config mpsc.dias_uteis)} '
        . '{--data= : Ingere só uma data específica (AAAA-MM-DD)}';

    protected $description = 'Ingere extratos de instauração de procedimentos do MPSC (DOE diário, PDF).';

    public function handle(MpscConector $conector): int
    {
        $datas = [];
        if ($this->option('data')) {
            $datas[] = Carbon::parse((string) $this->option('data'));
        } else {
            $dias = (int) ($this->option('dias') ?: config('mpsc.dias_uteis'));
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
            $extratos = $conector->extratosDaData($data);
            $novos = 0;
            $vistos = 0;
            foreach ($extratos as $e) {
                if (DB::table('jr_mpsc_extratos')->where('hash', $e['hash'])->exists()) {
                    $vistos++;

                    continue;
                }
                DB::table('jr_mpsc_extratos')->insert([
                    'source' => 'mpsc',
                    'hash' => $e['hash'],
                    'tipo_proc' => $e['tipo_proc'],
                    'numero' => $e['numero'],
                    'comarca' => $e['comarca'],
                    'municipio' => $conector->municipioDaComarca($e['comarca']),
                    'orgao' => $e['orgao_mp'],
                    'partes' => $e['partes'],
                    'objeto' => $e['objeto'],
                    'membro' => $e['membro'],
                    'data_pub' => $e['data_pub'],
                    'edicao' => $e['edicao'],
                    'url_fonte' => $e['url_fonte'],
                    'texto_bruto' => $e['texto_bruto'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $novos++;
            }
            $this->line(sprintf('  %s  %3d novos · %3d vistos · %d extratos', $data->toDateString(), $novos, $vistos, count($extratos)));
            $totNovos += $novos;
            $totVistos += $vistos;
        }

        $this->info(sprintf('Total: %d novos · %d vistos.', $totNovos, $totVistos));

        return self::SUCCESS;
    }
}
