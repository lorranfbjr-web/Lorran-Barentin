<?php

namespace App\Console\Commands;

use App\Services\Jr\FirecrawlConector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico — ingere proposições das câmaras SoftCâmaras/LEGISOFT (30
 * cidades tier1/tier2 atrás de reCAPTCHA) via FIRECRAWL em
 * jr_camara_proposicoes. ADITIVO e ISOLADO. CamaraScorer pontua sem mudança.
 *
 * BLOQUEADO 02/07/2026 (sem FIRECRAWL_API_KEY) — o comando recusa rodar e
 * explica o motivo. Quando a chave chegar: .env + JRCAM_FIRECRAWL_ATIVO=true,
 * PoC `--poc --dry` (Canelinha=SoftCâmaras + Tijucas=LEGISOFT), validar os
 * parsers contra o HTML real, aí descomentar o schedule (1 req/câmara/dia).
 */
class JrFirecrawlIngest extends Command
{
    protected $signature = 'jr:firecrawl-ingest '
        . '{--cidade= : Só esta câmara (casa pelo nome, ex.: "Canelinha")} '
        . '{--poc : Só as 2 câmaras de PoC (Canelinha + Tijucas)} '
        . '{--dry : Parseia e mostra sem gravar (validação de parser)} '
        . '{--ano-min= : Sobrepõe o piso de ano da config}';

    protected $description = 'Ingere proposições das câmaras gated (SoftCâmaras/LEGISOFT) via Firecrawl — dedup por hash.';

    public function handle(FirecrawlConector $conector): int
    {
        $cfg = config('camara.firecrawl');
        if (! $conector->disponivel()) {
            $this->error('BLOQUEADO: ' . $conector->motivoBloqueio());

            return self::FAILURE;
        }

        $anoMin = (int) ($this->option('ano-min') ?: $cfg['ano_min']);
        $cidadeFiltro = trim((string) $this->option('cidade'));

        $cidades = collect($cfg['cidades']);
        if ($this->option('poc')) {
            $cidades = $cidades->filter(fn ($c) => ! empty($c['poc']));
        }
        if ($cidadeFiltro !== '') {
            $cidades = $cidades->filter(fn ($c) => mb_stripos($c['cidade'], $cidadeFiltro) !== false);
        }
        if ($cidades->isEmpty()) {
            $this->error('Nenhuma câmara casou o filtro.');

            return self::FAILURE;
        }

        $this->info(sprintf('FIRECRAWL · %d câmara(s) · ano>=%d%s',
            $cidades->count(), $anoMin, $this->option('dry') ? ' · DRY' : ''));

        $totNovos = 0;
        $totVistos = 0;
        $primeira = true;
        foreach ($cidades as $cam) {
            if (! $primeira) {
                $conector->dorme(); // politeness entre chamadas (crédito + API)
            }
            $primeira = false;
            $novos = 0;
            $vistos = 0;
            try {
                foreach ($conector->proposicoes($cam, $anoMin) as $p) {
                    if ($this->option('dry')) {
                        $this->line("  [dry] {$p['municipio']} · {$p['titulo']}");

                        continue;
                    }
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
            $this->line(sprintf('  %-22s %3d novos · %3d vistos', $cam['cidade'], $novos, $vistos));
            $totNovos += $novos;
            $totVistos += $vistos;
        }

        $this->info(sprintf('Total: %d novos · %d vistos.', $totNovos, $totVistos));

        return self::SUCCESS;
    }
}
