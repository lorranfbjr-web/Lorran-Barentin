<?php

namespace App\Console\Commands;

use App\Services\Jr\SegundoOlhar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEGUNDO OLHAR — roda o 2º faro (GPT, cego) nos CANDIDATOS A PAUTA de uma fonte
 * do radar cívico (dom|camara|mpsc|tce). Grava score2/eh_pauta2/motivo2/model2 e
 * marca `divergente` quando Sonnet e GPT discordam (classe ou |Δscore| >= limiar).
 *
 * ADITIVO e fail-closed: sem chave OpenAI real, não roda. Não toca score_pauta.
 */
class JrSegundoOlhar extends Command
{
    protected $signature = 'jr:segundo-olhar '
        . '{fonte : dom|camara|mpsc|tce} '
        . '{--min= : Score mínimo do Sonnet pra entrar (default config min_score)} '
        . '{--lote= : Itens por chamada (default config lote)} '
        . '{--limit=0 : Máx. de itens nesta execução (0 = todos os candidatos pendentes)} '
        . '{--force : Re-opina mesmo quem já tem score2}';

    protected $description = 'Segundo olhar (GPT, cego) nos candidatos a pauta do radar cívico — diversidade de julgamento + flag de divergência.';

    /** fonte => [tabela, pk, ente(cols), titulo(col), texto(cols), contexto]. */
    private function mapa(): array
    {
        return [
            'dom' => [
                'tabela' => 'jr_dom_atos', 'pk' => 'ato_id',
                'ente' => ['municipio', 'orgao'], 'titulo' => 'titulo',
                'texto' => ['objeto_limpo', 'objeto', 'texto_bruto'],
                'contexto' => 'um ato de compra/licitação pública do Diário Oficial dos Municípios de SC',
            ],
            'camara' => [
                'tabela' => 'jr_camara_proposicoes', 'pk' => 'id',
                'ente' => ['municipio', 'orgao'], 'titulo' => 'titulo',
                'texto' => ['objeto_limpo', 'ementa', 'texto_bruto'],
                'contexto' => 'uma proposição (projeto de lei/resolução) de uma Câmara Municipal de SC',
            ],
            'mpsc' => [
                'tabela' => 'jr_mpsc_extratos', 'pk' => 'id',
                'ente' => ['comarca', 'municipio', 'orgao'], 'titulo' => 'partes',
                'texto' => ['objeto_limpo', 'objeto', 'texto_bruto'],
                'contexto' => 'um extrato de instauração de procedimento do Ministério Público (o MP abriu investigação)',
            ],
            'tce' => [
                'tabela' => 'jr_tce_decisoes', 'pk' => 'id',
                'ente' => ['municipio'], 'titulo' => 'objeto_limpo',
                'texto' => ['objeto_limpo', 'texto_bruto'],
                'contexto' => 'uma decisão/processo do Tribunal de Contas de SC',
            ],
        ];
    }

    public function handle(SegundoOlhar $olho): int
    {
        $fonte = (string) $this->argument('fonte');
        $mapa = $this->mapa();
        if (! isset($mapa[$fonte])) {
            $this->error('Fonte inválida. Use: ' . implode('|', array_keys($mapa)));

            return self::FAILURE;
        }
        if (! $olho->disponivel()) {
            $this->warn('Segundo olhar INERTE: sem chave OpenAI real (fail-closed). Configure OPENAI_API_KEY.');

            return self::SUCCESS;
        }

        $m = $mapa[$fonte];
        $min = $this->option('min') !== null && $this->option('min') !== ''
            ? (int) $this->option('min') : (int) config('segundo_olhar.min_score', 50);
        $lote = (int) ($this->option('lote') ?: config('segundo_olhar.lote', 8));
        $cap = (int) config('segundo_olhar.cap_chamadas', 600);
        $limiar = (int) config('segundo_olhar.limiar_divergencia', 25);
        $limit = (int) $this->option('limit');

        $q = DB::table($m['tabela'])
            ->whereNotNull('score_pauta')->where('score_pauta', '>=', $min);
        if (! $this->option('force')) {
            $q->whereNull('score2');
        }
        $q->orderByDesc('score_pauta');
        if ($limit > 0) {
            $q->limit($limit);
        }

        $cols = array_merge([$m['pk'], 'score_pauta'], $m['ente'], [$m['titulo']], $m['texto']);
        $cols = array_values(array_unique(array_filter($cols)));
        $pendentes = $q->get($cols);

        if ($pendentes->isEmpty()) {
            $this->info("Nada pra opinar em {$fonte} (candidatos score>={$min}).");

            return self::SUCCESS;
        }

        $lotes = $pendentes->chunk($lote);
        if ($lotes->count() > $cap) {
            $this->warn(sprintf('Plano: %d chamadas > cap %d. Use --limit pra cobrir em partes.', $lotes->count(), $cap));
        }
        $this->info(sprintf('%s · 2º olhar em %d candidatos (score>=%d) · %d chamadas · %s',
            $fonte, $pendentes->count(), $min, $lotes->count(), $olho->modelo()));

        $bar = $this->output->createProgressBar($lotes->count());
        $bar->start();
        $okItens = 0;
        $divs = 0;
        $erros = 0;
        foreach ($lotes as $chunk) {
            $itens = $chunk->map(fn ($r) => [
                'id' => (int) $r->{$m['pk']},
                'ente' => $this->primeiro($r, $m['ente']),
                'titulo' => (string) ($r->{$m['titulo']} ?? ''),
                'texto' => $this->primeiro($r, $m['texto']),
            ])->all();

            try {
                $vereditos = $olho->opinarLote($itens, $m['contexto']);
            } catch (\Throwable $e) {
                $erros++;
                $this->newLine();
                $this->warn('  lote falhou: ' . mb_substr($e->getMessage(), 0, 140));
                $bar->advance();

                continue;
            }

            foreach ($chunk as $r) {
                $id = (int) $r->{$m['pk']};
                if (! isset($vereditos[$id])) {
                    continue;
                }
                $v = $vereditos[$id];
                $s1 = (int) $r->score_pauta;
                $eraPauta1 = $s1 >= $min;                       // Sonnet viu pauta (entrou aqui)
                $divergente = (! $v['eh_pauta']) || abs($s1 - $v['score']) >= $limiar;
                if ($divergente) {
                    $divs++;
                }
                DB::table($m['tabela'])->where($m['pk'], $id)->update([
                    'score2' => $v['score'],
                    'eh_pauta2' => $v['eh_pauta'],
                    'motivo2' => $v['motivo'],
                    'model2' => $olho->modelo(),
                    'divergente' => $divergente,
                    'scored2_at' => now(),
                ]);
                $okItens++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('%s: %d opinados · %d divergentes (revisar) · %d lotes com erro.',
            $fonte, $okItens, $divs, $erros));

        return self::SUCCESS;
    }

    /** Primeiro valor não-vazio entre as colunas candidatas. */
    private function primeiro($row, array $cols): string
    {
        foreach ($cols as $c) {
            $v = trim((string) ($row->{$c} ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
}
