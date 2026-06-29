<?php

namespace App\Console\Commands;

use App\Services\Jr\FornecedorRastro;
use Illuminate\Console\Command;

/**
 * Radar Cívico FASE 8 — rastro de fornecedor CROSS-FONTE (best-effort).
 * Cruza empresas que aparecem em ≥2 municípios e/ou ≥2 fontes (DOM/MPSC/TCE).
 * Reporta o rastro + a COBERTURA honesta (não inventa dado). Read-only.
 */
class JrFornecedorRastro extends Command
{
    protected $signature = 'jr:fornecedor-rastro {--json= : Grava o resultado em JSON no caminho dado}';

    protected $description = 'Consolida fornecedor cross-fonte (empresa em N municípios/órgãos) — best-effort.';

    public function handle(FornecedorRastro $svc): int
    {
        $r = $svc->consolidar();
        $cob = $r['cobertura'];

        $this->info('COBERTURA (empresa extraível por fonte — heurística, fraca de propósito):');
        $this->line(sprintf('  DOM : %d/%d atos com fornecedor', $cob['dom']['com_empresa'], $cob['dom']['total']));
        $this->line(sprintf('  MPSC: %d/%d extratos com empresa nas partes', $cob['mpsc']['com_empresa'], $cob['mpsc']['total']));
        $this->line(sprintf('  TCE : %d processos (empresa esparsa no interessado/assunto)', $cob['tce']['total']));
        $this->line('  Câmara: sem fornecedor (legislativo).');
        $this->newLine();

        $emp = $r['empresas'];
        $this->info(sprintf('RASTRO: %d empresa(s) recorrente(s) (≥2 municípios e/ou ≥2 fontes):', count($emp)));
        foreach ($emp as $e) {
            $this->line(sprintf('  • %s — %d município(s) [%s] · fontes: %s · %d ocorrências',
                $e['empresa'], $e['n_municipios'], implode(', ', array_slice($e['municipios'], 0, 6)),
                implode('+', $e['fontes']), $e['ocorrencias']));
        }
        if (! $emp) {
            $this->warn('  Nenhuma recorrência ainda — base jovem. O mecanismo está pronto; a recorrência aparece com o acúmulo. (Não inventamos dado.)');
        }

        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info('JSON: ' . $this->option('json'));
        }

        return self::SUCCESS;
    }
}
