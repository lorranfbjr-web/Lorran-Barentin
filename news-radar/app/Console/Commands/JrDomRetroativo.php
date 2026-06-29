<?php

namespace App\Console\Commands;

use App\Services\Jr\DomConector;
use App\Services\Jr\DomIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OBJ1 — Crawler RETROATIVO do DOM/SC. Varre o HISTÓRICO pra trás aos poucos, em
 * background e baixa prioridade, SEM competir com o forward (jr:dom-ingest).
 *
 * Cursor persistido em jr_dom_estado ('retro_cursor' = data até onde já varremos
 * pra trás): cada execução baixa UM chunk (janela curta) mais antigo e recua o
 * cursor. Para quando alcança a profundidade-alvo (config dom.retro.alvo_dias).
 * Resume entre execuções (idempotente, dedup por hash). ISOLADO do juiz/radar.
 */
class JrDomRetroativo extends Command
{
    protected $signature = 'jr:dom-retroativo '
        . '{--chunk-dias= : Tamanho do passo (default config dom.retro.chunk_dias)} '
        . '{--alvo-dias= : Profundidade-alvo em dias (default config dom.retro.alvo_dias)} '
        . '{--max-paginas= : Teto de páginas/categoria por chunk (default config)} '
        . '{--chunks=1 : Quantos chunks varrer nesta execução (1 = um passo por run)} '
        . '{--reset : Reinicia o cursor a partir de hoje (revarre o histórico)} '
        . '{--status : Só mostra o estado do cursor, não varre}';

    protected $description = 'Crawler RETROATIVO DOM/SC: enche o histórico pra trás aos poucos (cursor persistido), sem competir com o forward.';

    private const K_CURSOR = 'retro_cursor';

    private const K_CONCLUIDO = 'retro_concluido';

    public function handle(DomConector $conector): int
    {
        $cfg = config('dom');
        $chunkDias = (int) ($this->option('chunk-dias') ?: $cfg['retro']['chunk_dias']);
        $alvoDias = (int) ($this->option('alvo-dias') ?: $cfg['retro']['alvo_dias']);
        $maxPag = (int) ($this->option('max-paginas') ?: $cfg['retro']['max_paginas']);
        $categorias = $cfg['categorias'];

        $hoje = Carbon::now()->startOfDay();
        $limite = $hoje->copy()->subDays($alvoDias); // não passa daqui pra trás

        if ($this->option('reset')) {
            $this->setEstado(self::K_CURSOR, $hoje->toDateString());
            $this->setEstado(self::K_CONCLUIDO, '0');
            $this->info('Cursor retroativo reiniciado a partir de hoje.');
        }

        $cursor = $this->getEstado(self::K_CURSOR);
        $cursorData = $cursor ? Carbon::parse($cursor)->startOfDay() : $hoje->copy();

        if ($this->option('status')) {
            $prof = $hoje->diffInDays($cursorData);
            $this->info(sprintf('Cursor: %s (%d dias atrás) | alvo: %s (%d dias) | concluído: %s',
                $cursorData->toDateString(), $prof, $limite->toDateString(), $alvoDias,
                $this->getEstado(self::K_CONCLUIDO) === '1' ? 'sim' : 'não'));

            return self::SUCCESS;
        }

        if ($cursorData->lessThanOrEqualTo($limite)) {
            $this->setEstado(self::K_CONCLUIDO, '1');
            $this->info(sprintf('Retroativo já alcançou o alvo (%s, %d dias). Nada a fazer.',
                $limite->toDateString(), $alvoDias));

            return self::SUCCESS;
        }

        $ingestor = new DomIngestor($conector);
        $chunks = max(1, (int) $this->option('chunks'));
        $totalNovos = 0;

        for ($i = 0; $i < $chunks; $i++) {
            if ($cursorData->lessThanOrEqualTo($limite)) {
                $this->setEstado(self::K_CONCLUIDO, '1');
                $this->info('Alvo alcançado durante a varredura — parando.');
                break;
            }
            $fim = $cursorData->copy();
            $ini = $cursorData->copy()->subDays($chunkDias);
            if ($ini->lessThan($limite)) {
                $ini = $limite->copy();
            }

            $this->info(sprintf('Chunk retroativo: %s → %s', $ini->toDateString(), $fim->toDateString()));
            // sem early-stop: o retroativo QUER varrer a janela inteira
            $r = $ingestor->ingerirJanela($categorias, $ini, $fim, $maxPag, 0,
                fn (string $cat, int $n) => $this->line("   {$cat}: +{$n}"));
            $totalNovos += $r['novos'];
            $this->line(sprintf('   → %d vistos, %d novos', $r['vistos'], $r['novos']));

            // recua o cursor (sobrepõe 0 dias; janela é [ini, fim], próxima é antes de ini)
            $cursorData = $ini->copy();
            $this->setEstado(self::K_CURSOR, $cursorData->toDateString());

            if ($cursorData->lessThanOrEqualTo($limite)) {
                $this->setEstado(self::K_CONCLUIDO, '1');
                $this->info('Alvo alcançado — retroativo concluído.');
                break;
            }
        }

        $total = DB::table('jr_dom_atos')->count();
        $min = DB::table('jr_dom_atos')->min('data_pub');
        $this->newLine();
        $this->info(sprintf('Retroativo: +%d novos neste run. Cursor agora em %s. Histórico mais antigo na base: %s. Total: %d atos.',
            $totalNovos, $cursorData->toDateString(), $min ?: '—', $total));

        return self::SUCCESS;
    }

    private function getEstado(string $chave): ?string
    {
        $v = DB::table('jr_dom_estado')->where('chave', $chave)->value('valor');

        return $v !== null ? (string) $v : null;
    }

    private function setEstado(string $chave, string $valor): void
    {
        DB::table('jr_dom_estado')->updateOrInsert(
            ['chave' => $chave],
            ['valor' => $valor, 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
