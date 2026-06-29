<?php

namespace App\Console\Commands;

use App\Services\Jr\DomGeografia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Registro de ENTIDADES do DOM/SC (PoC busca por órgão, ADITIVO/ISOLADO).
 *
 * Raspa a página pública "Quais entidades publicam?" (≈1039 entidades), onde
 * cada entidade tem um codigoEntidade — o ÚNICO filtro estruturado de entidade
 * que o DOM expõe statelessly (via feed RSS por entidade). Grava um JSON estático
 * (codigo, nome, tipo, municipio, regiao) consumido pela /dom-busca.
 *
 * Não toca juiz/radar/captura. Re-rodar regenera o JSON (entidades mudam pouco).
 */
class JrDomEntidades extends Command
{
    protected $signature = 'jr:dom-entidades {--dry : Só conta, não grava o JSON}';

    protected $description = 'Raspa o registro de entidades do DOM/SC (codigoEntidade) pra um JSON usado na busca por órgão.';

    /** Onde o JSON fica (committado no repo, deploy junto). */
    public const CAMINHO = 'resources/data/dom-entidades.json';

    public function handle(): int
    {
        $base = rtrim((string) config('dom.base_url'), '/');
        $ua = (string) config('dom.user_agent');

        $this->info('Buscando página de entidades…');
        $resp = Http::withHeaders(['User-Agent' => $ua])->timeout(40)->retry(2, 1500, throw: false)
            ->get($base . '/', ['r' => 'site/page', 'view' => 'entidades']);
        if (! $resp->ok()) {
            $this->error('Falha ao buscar a página de entidades: HTTP ' . $resp->status());

            return self::FAILURE;
        }

        $html = $resp->body();
        // <a ...codigoEntidade=N...>NOME</a> — em ordem de documento (blocos por município)
        if (! preg_match_all('/codigoEntidade=(\d+)[^>]*>([^<]+)<\/a>/i', $html, $m, PREG_SET_ORDER)) {
            $this->error('Nenhuma entidade casou o padrão — layout mudou?');

            return self::FAILURE;
        }

        $entidades = [];
        $vistos = [];
        $municipioAtual = null; // carry-forward: bloco começa na Prefeitura do município
        foreach ($m as $par) {
            $codigo = (int) $par[1];
            $nome = trim(html_entity_decode($par[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($nome === '' || isset($vistos[$codigo])) {
                continue;
            }
            $vistos[$codigo] = true;

            $tipo = $this->tipoDe($nome);
            // âncora de bloco: "Prefeitura (Municipal) de X" reseta o município atual
            if ($tipo === 'Prefeitura') {
                $municipioAtual = DomGeografia::municipioNoFim($nome);
            }

            // município: 1º o sufixo do próprio nome; 2º carry-forward só p/ genéricos
            $municipio = DomGeografia::municipioNoFim($nome);
            if ($municipio === null && in_array($tipo, ['Convênio', 'Autarquia/Serviço', 'Fundo'], true)) {
                $municipio = $municipioAtual;
            }
            // consórcio/associação NÃO herda município (é regional de verdade)
            if (in_array($tipo, ['Consórcio', 'Associação/Regional'], true)) {
                $municipio = null;
            }

            $entidades[] = [
                'codigo' => $codigo,
                'nome' => $nome,
                'tipo' => $tipo,
                'municipio' => $municipio,
                'regiao' => $municipio ? DomGeografia::regiao($municipio) : null,
            ];
        }

        $comMun = count(array_filter($entidades, fn ($e) => $e['municipio'] !== null));
        $this->info(sprintf('Entidades: %d (%d com município, %d regionais).',
            count($entidades), $comMun, count($entidades) - $comMun));

        if ($this->option('dry')) {
            foreach (array_slice($entidades, 0, 12) as $e) {
                $this->line(sprintf('  [%s] %s → %s', $e['tipo'], $e['nome'], $e['municipio'] ?? '(regional)'));
            }

            return self::SUCCESS;
        }

        $caminho = base_path(self::CAMINHO);
        @mkdir(dirname($caminho), 0775, true);
        file_put_contents($caminho, json_encode([
            'gerado_em' => now()->toIso8601String(),
            'total' => count($entidades),
            'entidades' => $entidades,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->info("Gravado: {$caminho}");

        return self::SUCCESS;
    }

    private function tipoDe(string $nome): string
    {
        $n = mb_strtolower($nome, 'UTF-8');

        return match (true) {
            str_starts_with($n, 'prefeitura')                         => 'Prefeitura',
            str_contains($n, 'câmara') || str_contains($n, 'camara')  => 'Câmara',
            str_contains($n, 'convênio') || str_contains($n, 'convenio') => 'Convênio',
            str_starts_with($n, 'fundo')                              => 'Fundo',
            str_contains($n, 'consórcio') || str_contains($n, 'consorcio')
                || str_starts_with($n, 'ci') && preg_match('/^ci[ms]?\b|^cis|^cim/i', $nome) => 'Consórcio',
            str_starts_with($n, 'associação') || str_starts_with($n, 'associacao')
                || preg_match('/^am[a-z]{2,}/i', $nome)               => 'Associação/Regional',
            str_contains($n, 'instituto') || str_contains($n, 'previdência') || str_contains($n, 'previdencia')
                || str_contains($n, 'iprev') || str_contains($n, 'ipm') => 'Instituto/Previdência',
            str_contains($n, 'serviço') || str_contains($n, 'servico') || str_contains($n, 'autarquia')
                || str_contains($n, 'agência') || str_contains($n, 'agencia') || str_contains($n, 'samae')
                || str_contains($n, 'companhia') || str_contains($n, 'departamento') => 'Autarquia/Serviço',
            default                                                   => 'Outros',
        };
    }
}
