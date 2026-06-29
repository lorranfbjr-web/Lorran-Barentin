<?php

namespace App\Console\Commands;

use App\Services\Jr\DomObjetoLimpo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill do objeto_limpo nos atos JÁ ingeridos do DOM/SC. Recalcula a partir
 * do texto_bruto (heurística determinística, sem LLM, custo zero). Idempotente.
 *
 * ADITIVO/ISOLADO: só preenche a coluna objeto_limpo de jr_dom_atos; não toca em
 * score/juiz/captura. Pros atos pontuados do radar, o Sonnet poli por cima depois.
 */
class JrDomBackfill extends Command
{
    protected $signature = 'jr:dom-backfill '
        . '{--force : Recalcula mesmo quem já tem objeto_limpo}';

    protected $description = 'Backfill: recalcula objeto_limpo (heurística) dos atos já ingeridos no DOM/SC.';

    public function handle(): int
    {
        $q = DB::table('jr_dom_atos');
        if (! $this->option('force')) {
            $q->whereNull('objeto_limpo');
        }
        $atos = $q->get(['ato_id', 'titulo', 'texto_bruto']);

        if ($atos->isEmpty()) {
            $this->info('Nada pra backfillar (use --force pra recalcular tudo).');

            return self::SUCCESS;
        }

        $this->info("Backfillando objeto_limpo de {$atos->count()} atos…");
        $bar = $this->output->createProgressBar($atos->count());
        $bar->start();
        $ok = 0;
        foreach ($atos as $a) {
            $limpo = DomObjetoLimpo::limpar($a->texto_bruto, $a->titulo);
            DB::table('jr_dom_atos')->where('ato_id', $a->ato_id)->update([
                'objeto_limpo' => $limpo,
                'updated_at' => now(),
            ]);
            $ok++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("objeto_limpo preenchido em {$ok} atos.");

        return self::SUCCESS;
    }
}
