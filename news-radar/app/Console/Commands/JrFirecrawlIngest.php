<?php

namespace App\Console\Commands;

use App\Services\Jr\FirecrawlConector;
use App\Services\Jr\Recencia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico BLOCO 5 (02/07) — ingere proposições das câmaras SoftCâmaras
 * GATED via FIRECRAWL (search → scrape profundo stealth) em
 * jr_camara_proposicoes. ADITIVO e ISOLADO. CamaraScorer pontua sem mudança.
 *
 * 💳 METERED com teto RÍGIDO (--teto, default 300 créditos ≈ 60 scrapes
 * stealth). Ledger estimado + leitura REAL de créditos antes/depois. 402 =
 * para tudo na hora. Só roda nas câmaras com firecrawl_ativo=true na config
 * (PoC: Canelinha + Nova Trento); as demais ficam prontas-mas-desativadas —
 * escalar é decisão de plano do Lorran. LEGISOFT segue BLOQUEADO (provado).
 */
class JrFirecrawlIngest extends Command
{
    protected $signature = 'jr:firecrawl-ingest '
        . '{--cidade= : Só esta câmara (casa pelo nome, ex.: "Canelinha")} '
        . '{--dry : Parseia e mostra sem gravar (validação de parser)} '
        . '{--teto=300 : Teto de créditos ESTIMADOS desta execução} '
        . '{--max-scrapes=12 : Máx. de URLs profundas por câmara} '
        . '{--ano-min= : Sobrepõe o piso de ano da config}';

    protected $description = 'Ingere proposições das câmaras gated (SoftCâmaras) via Firecrawl search→scrape stealth — teto rígido de créditos.';

    public function handle(FirecrawlConector $conector): int
    {
        $cfg = config('camara.firecrawl');
        if (! $conector->disponivel()) {
            $this->error('BLOQUEADO: ' . $conector->motivoBloqueio());

            return self::FAILURE;
        }

        $anoMin = (int) ($this->option('ano-min') ?: $cfg['ano_min']);
        $teto = max(10, (int) $this->option('teto'));
        $maxScrapes = max(1, (int) $this->option('max-scrapes'));
        $cidadeFiltro = trim((string) $this->option('cidade'));

        $cidades = collect($cfg['cidades'])->filter(fn ($c) => ! empty($c['firecrawl_ativo']));
        if ($cidadeFiltro !== '') {
            $cidades = $cidades->filter(fn ($c) => mb_stripos($c['cidade'], $cidadeFiltro) !== false);
        }
        if ($cidades->isEmpty()) {
            $this->error('Nenhuma câmara com firecrawl_ativo=true casou o filtro.');

            return self::FAILURE;
        }

        $antes = $conector->creditosRestantesReais();
        $this->info(sprintf('FIRECRAWL · %d câmara(s) · ano>=%d · teto=%d créditos · restantes reais: %s%s',
            $cidades->count(), $anoMin, $teto, $antes ?? '?', $this->option('dry') ? ' · DRY' : ''));

        $totNovos = 0;
        $totVistos = 0;
        foreach ($cidades as $cam) {
            if ($conector->creditosGastos() >= $teto || $conector->semCredito()) {
                $this->warn('TETO/crédito atingido — parando o Firecrawl.');
                break;
            }
            $novos = 0;
            $vistos = 0;
            try {
                $ano = (int) date('Y');
                $urls = $conector->descobrirUrls($cam, $ano);
                $this->line(sprintf('  %-14s search → %d URL(s) profunda(s)', $cam['cidade'], count($urls)));

                foreach (array_slice($urls, 0, $maxScrapes) as $url) {
                    if ($conector->creditosGastos() + FirecrawlConector::CUSTO_SCRAPE_STEALTH > $teto
                        || $conector->semCredito()) {
                        $this->warn('  teto de créditos bateria no próximo scrape — parando.');
                        break 2;
                    }
                    $conector->dorme(); // 1 req/s — educado
                    $r = $conector->scrapeProfundo($url);
                    if ($r === null) {
                        $this->line("    ✗ scrape falhou: {$url}");

                        continue;
                    }
                    $p = $conector->parseDetalhe($r['markdown'], $url, $cam, $anoMin);
                    if ($p === null) {
                        $this->line("    ✗ não parseou (gate/sem proposição): {$url}");

                        continue;
                    }
                    if ($this->option('dry')) {
                        $this->line("    [dry] {$p['titulo']} · data={$p['data_pub']} · " . mb_substr((string) $p['ementa'], 0, 70));
                        $novos++;

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
                        // BLOCO 1: data furada (futura/implausível) vira NULL + flag
                        ...Recencia::sanitizar($p['data_pub']),
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
            $this->line(sprintf('  %-22s %3d novos · %3d vistos · %d créditos estimados até aqui',
                $cam['cidade'], $novos, $vistos, $conector->creditosGastos()));
            $totNovos += $novos;
            $totVistos += $vistos;
        }

        $depois = $conector->creditosRestantesReais();
        $this->info(sprintf('Total: %d novos · %d vistos · créditos estimados: %d · reais antes→depois: %s→%s (consumo real: %s)',
            $totNovos, $totVistos, $conector->creditosGastos(),
            $antes ?? '?', $depois ?? '?',
            ($antes !== null && $depois !== null) ? ($antes - $depois) : '?'));

        return self::SUCCESS;
    }
}
