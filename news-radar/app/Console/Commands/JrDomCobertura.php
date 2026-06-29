<?php

namespace App\Console\Commands;

use App\Services\Jr\DomConector;
use App\Services\Jr\DomEntidades;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * OBJ3 — Mapa de cobertura das cidades prioritárias no DOM/SC.
 *
 * Pra cada cidade prioritária, descobre se a PREFEITURA e a CÂMARA têm entidade
 * ATIVA no Diário Oficial dos Municípios (publicou nos últimos ~90 dias) ou está
 * FORA (ex.: Itajaí, que saiu do DOM em out/2020 — segue no registro, mas não
 * publica há anos; por isso sondamos RECÊNCIA via RSS, não só presença).
 *
 * Gera /home/jr/goals/SCOPING-cobertura-dom.md. NÃO constrói conector pras FORA —
 * só MAPEIA onde provavelmente publicam, pra decisão futura. READ-ONLY/aditivo.
 */
class JrDomCobertura extends Command
{
    protected $signature = 'jr:dom-cobertura '
        . '{--dias=90 : Janela de recência pra considerar a entidade ATIVA} '
        . '{--saida=/home/jr/goals/SCOPING-cobertura-dom.md : Arquivo de saída}';

    protected $description = 'Mapa de cobertura DOM/SC: sonda (RSS) se Prefeitura/Câmara das cidades prioritárias publicam no DOM (ATIVO/FORA).';

    /** Cidades prioritárias (ordem do GOAL). */
    private const CIDADES = [
        'Florianópolis', 'Joinville', 'Blumenau', 'São José', 'Chapecó', 'Itajaí',
        'Criciúma', 'Jaraguá do Sul', 'Palhoça', 'Lages', 'Balneário Camboriú',
        'Brusque', 'Tubarão', 'São Bento do Sul', 'Caçador', 'Camboriú',
        'Navegantes', 'Concórdia', 'Rio do Sul', 'Gaspar', 'Indaial', 'Araranguá',
        'Biguaçu', 'Itapema', 'Mafra', 'Canoinhas', 'Tijucas', 'Porto Belo',
        'Bombinhas', 'Canelinha', 'São João Batista', 'Nova Trento',
    ];

    /** Onde a cidade provavelmente publica quando está FORA do DOM (best-effort). */
    private const ONDE_FORA = [
        'Itajaí' => 'Saiu do DOM/SC em out/2020. Publica em Diário Oficial próprio (Imprensa Oficial do Município) — provável portal itajai.sc.gov.br / DOM municipal.',
        'Joinville' => 'Maiores capitais regionais costumam ter Diário Oficial Eletrônico próprio (DOE municipal no site da prefeitura).',
        'Criciúma' => 'Verificar Diário Oficial Eletrônico próprio no site da prefeitura.',
    ];

    public function handle(DomConector $conector): int
    {
        $dias = (int) $this->option('dias');
        $hoje = Carbon::now()->startOfDay();
        $corte = $hoje->copy()->subDays($dias)->toDateString();

        $linhas = [];
        $resumoFora = [];
        $this->info(sprintf('Sondando %d cidades (recência %d dias, corte %s)…', count(self::CIDADES), $dias, $corte));

        foreach (self::CIDADES as $cidade) {
            $ents = DomEntidades::porMunicipio($cidade);
            $pref = $this->primeiraDoTipo($ents, 'Prefeitura');
            $cam = $this->primeiraDoTipo($ents, 'Câmara');

            $statusPref = $this->status($conector, $pref, $corte);
            $statusCam = $this->status($conector, $cam, $corte);

            $linhas[] = compact('cidade') + [
                'pref' => $statusPref,
                'cam' => $statusCam,
            ];

            $tag = fn ($s) => $s['classe'] === 'ATIVO' ? '🟢 ATIVO' : ($s['classe'] === 'FORA' ? '🔴 FORA' : '⚪ s/registro');
            $this->line(sprintf('  %-22s Pref: %-14s (%s)  | Câmara: %-14s (%s)',
                $cidade, $tag($statusPref), $statusPref['ultima'] ?: '—', $tag($statusCam), $statusCam['ultima'] ?: '—'));

            if ($statusPref['classe'] !== 'ATIVO') {
                $resumoFora[$cidade] = $statusPref['classe'];
            }
        }

        $md = $this->montarMarkdown($linhas, $dias, $corte, $hoje);
        $saida = (string) $this->option('saida');
        @file_put_contents($saida, $md);

        $foraCount = count(array_filter($linhas, fn ($l) => $l['pref']['classe'] !== 'ATIVO'));
        $this->newLine();
        $this->info(sprintf('Cobertura: %d/%d Prefeituras ATIVAS no DOM. %d fora/sem-registro. Mapa: %s',
            count($linhas) - $foraCount, count($linhas), $foraCount, $saida));

        return self::SUCCESS;
    }

    /** @param array<int,array> $ents */
    private function primeiraDoTipo(array $ents, string $tipo): ?array
    {
        foreach ($ents as $e) {
            if (($e['tipo'] ?? null) === $tipo) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @return array{classe:string,ultima:?string,codigo:?int,nome:?string}
     *   classe: ATIVO (publicou >= corte) | FORA (publica antigo/nunca) | SEM_REGISTRO
     */
    private function status(DomConector $conector, ?array $ent, string $corte): array
    {
        if (! $ent) {
            return ['classe' => 'SEM_REGISTRO', 'ultima' => null, 'codigo' => null, 'nome' => null];
        }
        $ultima = $conector->ultimaPublicacaoEntidade((int) $ent['codigo']);
        $classe = ($ultima !== null && $ultima >= $corte) ? 'ATIVO' : 'FORA';

        return ['classe' => $classe, 'ultima' => $ultima, 'codigo' => (int) $ent['codigo'], 'nome' => $ent['nome']];
    }

    /** @param array<int,array> $linhas */
    private function montarMarkdown(array $linhas, int $dias, string $corte, Carbon $hoje): string
    {
        $emoji = fn ($s) => $s['classe'] === 'ATIVO' ? '🟢 ATIVO' : ($s['classe'] === 'FORA' ? '🔴 FORA' : '⚪ s/ registro');
        $ult = fn ($s) => $s['ultima'] ? ('última: ' . $s['ultima']) : '—';

        $tbl = "| Cidade | Prefeitura | Última pub. | Câmara | Última pub. |\n|---|---|---|---|---|\n";
        $fora = [];
        foreach ($linhas as $l) {
            $tbl .= sprintf("| %s | %s | %s | %s | %s |\n",
                $l['cidade'],
                $emoji($l['pref']), $l['pref']['ultima'] ?: '—',
                $emoji($l['cam']), $l['cam']['ultima'] ?: '—');
            if ($l['pref']['classe'] !== 'ATIVO') {
                $fora[] = $l;
            }
        }

        $foraMd = '';
        if ($fora) {
            $foraMd = "\n## Prefeituras FORA / sem registro ativo — pra conector próprio depois\n\n";
            $foraMd .= "> NÃO construir agora — só mapeado onde provavelmente publicam.\n\n";
            foreach ($fora as $l) {
                $onde = self::ONDE_FORA[$l['cidade']] ?? 'Verificar Diário Oficial Eletrônico próprio no site oficial da prefeitura (município provavelmente migrou pra imprensa oficial própria).';
                $cod = $l['pref']['codigo'] ? " (codigoEntidade {$l['pref']['codigo']}, última pub. " . ($l['pref']['ultima'] ?: 'desconhecida') . ')' : ' (sem entidade no registro do DOM)';
                $foraMd .= "- **{$l['cidade']}**{$cod}: {$onde}\n";
            }
        }

        $totalAtivo = count(array_filter($linhas, fn ($l) => $l['pref']['classe'] === 'ATIVO'));
        $totalCidades = count($linhas);
        $geradoEm = $hoje->format('d/m/Y');

        return <<<MD
# SCOPING — Cobertura DOM/SC das cidades prioritárias

Gerado em {$geradoEm} por `jr:dom-cobertura`. Sonda via RSS por entidade
(codigoEntidade) a **recência** de publicação de cada Prefeitura/Câmara. "ATIVO"
= publicou nos últimos {$dias} dias (corte {$corte}). "FORA" = entidade existe no
registro mas a publicação mais recente é anterior ao corte (saiu do DOM ou
migrou pra diário próprio). "s/ registro" = nem aparece no registro de entidades.

⚖️ Mapa operacional, não editorial: indica ONDE buscar, não o que noticiar.

**Resumo:** {$totalAtivo}/{$totalCidades} Prefeituras prioritárias ATIVAS no DOM/SC.

## Tabela

{$tbl}
{$foraMd}
## Nota metodológica

- Presença no registro de entidades (`jr:dom-entidades`) **não** garante atividade:
  o registro lista quem um dia publicou. Itajaí, p.ex., segue listado mas saiu do
  DOM em out/2020 — por isso a régua é **recência via RSS**, não presença.
- Câmaras "s/ registro" geralmente publicam **proposições/leis** em sistema
  legislativo próprio (não atos administrativos no DOM) — fora do escopo deste radar.
- A janela de recência é configurável (`--dias`).
MD;
    }
}
