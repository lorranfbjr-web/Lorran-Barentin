<?php

namespace App\Console\Commands;

use App\Services\Jr\TjscConector;
use Illuminate\Console\Command;

/**
 * Radar Cívico FASE 6 — SONDA EXPERIMENTAL do DJe-TJSC (read-only, sem DB).
 *
 * Varre N edições recentes (pra trás a partir de --edicao), aplica o filtro
 * agressivo (ente público + substância) e REPORTA o yield + os blocos que
 * passam. Serve pra MEDIR o sinal/ruído e decidir se vale um conector — que hoje
 * NÃO existe de propósito (yield ~0,4/dia e mesmo esses são ruído). Re-rodável
 * a qualquer momento. Ver goals/SCOPING-fase6-tjsc.md.
 */
class JrTjscProbe extends Command
{
    protected $signature = 'jr:tjsc-probe '
        . '{--edicao= : Edição inicial (mais recente; ex.: 4753). OBRIGATÓRIO} '
        . '{--n=5 : Quantas edições pra trás varrer} '
        . '{--dump : Mostra o texto dos blocos que passam}';

    protected $description = 'EXPERIMENTAL: sonda o DJe-TJSC e mede o yield jornalístico (read-only). Não ingere.';

    public function handle(TjscConector $conector): int
    {
        $edIni = (int) $this->option('edicao');
        if ($edIni <= 0) {
            $this->error('Informe --edicao (ex.: --edicao=4753). Descubra a atual em busca.tjsc.jus.br/dje-consulta/.');

            return self::FAILURE;
        }
        $n = max(1, (int) $this->option('n'));
        $cadernos = config('tjsc.cadernos');

        $this->warn('⚠️ EXPERIMENTAL — o DJe publica intimações/relações, não o texto das decisões. Yield baixo é esperado.');
        $total = 0;
        for ($i = 0; $i < $n; $i++) {
            $ed = $edIni - $i;
            foreach ($cadernos as $cd) {
                $blocos = $conector->blocosRelevantes($ed, $cd);
                if ($blocos) {
                    $total += count($blocos);
                    $this->info(sprintf('edição %d · caderno %d → %d bloco(s) relevante(s)', $ed, $cd, count($blocos)));
                    if ($this->option('dump')) {
                        foreach ($blocos as $b) {
                            $this->line(sprintf('   • %s | %s | %s', $b['processo'] ?? '?', $b['classe'] ?? '?', $b['ente_publico'] ?? '?'));
                            $this->line('     ' . mb_substr((string) $b['texto_bruto'], 0, 200));
                        }
                    }
                } else {
                    $this->line(sprintf('edição %d · caderno %d → 0', $ed, $cd));
                }
            }
        }
        $this->newLine();
        $this->info(sprintf('TOTAL: %d bloco(s) ente-público+substância em %d edições (%d cadernos/edição).', $total, $n, count($cadernos)));
        $this->line('Conclusão: sinal/ruído ruim demais pra conector de produção (ver SCOPING-fase6-tjsc.md).');

        return self::SUCCESS;
    }
}
