<?php

namespace App\Console\Commands;

use App\Services\Jr\DomConector;
use App\Services\Jr\DomIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FASE 1 — Ingestão FORWARD do DOM/SC. Varre a busca pública do Diário Oficial
 * dos Municípios de SC por categorias de compras (licitação/contrato/dispensa/
 * ata RP), TODOS os municípios, janela curta de N dias, e grava em jr_dom_atos.
 *
 * PRODUÇÃO (OBJ1): agendado na grade /5 e BARATO — com --parar-vistos a listagem
 * (recente-primeiro) para de paginar assim que bate no que já temos, então cada
 * ciclo só puxa o que é novo. Idempotente: dedup por hash(ato_id). ADITIVO e
 * ISOLADO: não toca em nada do radar/juiz/captura. O RETROATIVO (histórico) é o
 * jr:dom-retroativo, que roda em background sem competir com este.
 */
class JrDomIngest extends Command
{
    protected $signature = 'jr:dom-ingest '
        . '{--dias= : Janela em dias (default config dom.dias=14)} '
        . '{--categorias= : Lista separada por vírgula (default config dom.categorias)} '
        . '{--max-paginas= : Teto de páginas por categoria, 10 atos/pág (default config)} '
        . '{--parar-vistos=0 : FORWARD: para a categoria após N atos já-vistos seguidos (0=desliga; varre a janela toda)} '
        . '{--dry : Só mostra os totais reportados pela busca, sem gravar}';

    protected $description = 'Ingestão FORWARD DOM/SC: minera atos de compras públicas de todos os municípios de SC (janela N dias) pra jr_dom_atos.';

    public function handle(DomConector $conector): int
    {
        $cfg = config('dom');
        $dias = (int) ($this->option('dias') ?: $cfg['dias']);
        $maxPag = (int) ($this->option('max-paginas') ?: $cfg['max_paginas']);
        $pararVistos = (int) $this->option('parar-vistos');
        $categorias = $this->option('categorias')
            ? array_map('trim', explode(',', (string) $this->option('categorias')))
            : $cfg['categorias'];

        $fim = Carbon::now();
        $ini = $fim->copy()->subDays($dias);
        $this->info(sprintf('Janela: %s → %s (%d dias) | categorias: %s%s',
            $ini->toDateString(), $fim->toDateString(), $dias, implode(', ', $categorias),
            $pararVistos > 0 ? " | early-stop={$pararVistos}" : ''));

        if ($this->option('dry')) {
            foreach ($categorias as $cat) {
                $this->line(sprintf('  %-28s total reportado: %d', $cat, $conector->total($cat, $ini, $fim)));
            }

            return self::SUCCESS;
        }

        $ingestor = new DomIngestor($conector);
        $r = $ingestor->ingerirJanela($categorias, $ini, $fim, $maxPag, $pararVistos,
            fn (string $cat, int $n) => $this->line("→ {$cat}: +{$n} novos"));

        $municipios = DB::table('jr_dom_atos')->distinct()->count('municipio');
        $total = DB::table('jr_dom_atos')->count();
        $this->newLine();
        $this->info(sprintf('Ingestão FORWARD: %d atos vistos, %d novos gravados.', $r['vistos'], $r['novos']));
        $this->info(sprintf('Tabela jr_dom_atos: %d atos no total, %d municípios distintos.', $total, $municipios));

        return self::SUCCESS;
    }
}
