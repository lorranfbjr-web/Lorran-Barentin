<?php

namespace App\Console\Commands;

use App\Services\Jr\JuizLlm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEGUNDO OLHAR no juiz de notícias — roda o MESMO prompt do juiz no gpt-4o-mini
 * (driver OpenAI forçado), CEGO (só título+lead, não vê o veredito do Sonnet), nos
 * itens que o juiz marcou QUENTE. Grava score2/eh_pauta2 e marca divergente2 quando
 * Sonnet e gpt discordam. Diversidade de julgamento no MESMO critério (só muda o
 * modelo). Custa centavos (OpenAI, ZERO Max). ADITIVO: não toca o veredito do juiz.
 */
class JrSegundoOlharJuiz extends Command
{
    protected $signature = 'jr:segundo-olhar-juiz '
        . '{--dias=3 : Só quentes julgados nos últimos N dias} '
        . '{--lote=8 : Itens por chamada} '
        . '{--limit=0 : Máx. de itens (0 = todos os candidatos)} '
        . '{--force : Re-opina mesmo quem já tem score2}';

    protected $description = 'Segundo olhar (gpt-4o-mini, cego) nos itens QUENTES do juiz — diversidade de julgamento + flag de divergência.';

    public function handle(): int
    {
        // Instância do juiz com driver OpenAI FORÇADO — mesmo prompt, modelo diferente.
        $juiz2 = new JuizLlm(null, 'openai');
        if ($juiz2->driver() !== 'openai') {
            $this->warn('Segundo olhar INERTE: sem chave OpenAI real (fail-closed).');

            return self::SUCCESS;
        }

        $limiar = (int) config('segundo_olhar.limiar_divergencia', 25);
        $lote = (int) $this->option('lote');
        $dias = (int) $this->option('dias');
        $limit = (int) $this->option('limit');

        $q = DB::table('jr_link_extracao')
            ->where('temperatura_juiz', 'quente')
            ->whereNotNull('juiz_julgado_em')
            ->where('juiz_julgado_em', '>=', now()->subDays($dias));
        if (! $this->option('force')) {
            $q->whereNull('score2');
        }
        $q->orderByDesc('juiz_julgado_em');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $pendentes = $q->get(['id', 'titulo', 'lead', 'score_editorial']);

        if ($pendentes->isEmpty()) {
            $this->info("Nada pra opinar (quentes dos últimos {$dias} dias).");

            return self::SUCCESS;
        }

        $lotes = $pendentes->chunk($lote);
        $this->info(sprintf('2º olhar em %d quentes · %d chamadas · gpt-4o-mini (cego, mesmo prompt do juiz)',
            $pendentes->count(), $lotes->count()));

        $bar = $this->output->createProgressBar($lotes->count());
        $bar->start();
        $ok = 0;
        $divs = 0;
        $erros = 0;
        foreach ($lotes as $chunk) {
            $itens = $chunk->map(fn ($r) => [
                'id' => (int) $r->id,
                'titulo' => (string) $r->titulo,
                'lead' => (string) ($r->lead ?? ''),
            ])->all();

            try {
                $vereditos = $juiz2->julgarLote($itens);
            } catch (\Throwable $e) {
                $erros++;
                $bar->advance();

                continue;
            }

            foreach ($chunk as $r) {
                $id = (int) $r->id;
                if (! isset($vereditos[$id])) {
                    continue;
                }
                $v = $vereditos[$id];
                $s2 = (int) ($v['score_llm'] ?? $v['score_editorial'] ?? 0);
                $ehPauta2 = (bool) ($v['eh_pauta'] ?? false);
                $s1 = (int) $r->score_editorial;
                // divergente: gpt diz não-pauta (juiz disse quente) OU gap grande de score
                $divergente = (! $ehPauta2) || abs($s1 - $s2) >= $limiar;
                if ($divergente) {
                    $divs++;
                }
                DB::table('jr_link_extracao')->where('id', $id)->update([
                    'score2' => max(0, min(100, $s2)),
                    'eh_pauta2' => $ehPauta2,
                    'motivo2' => mb_substr((string) ($v['motivo'] ?? ''), 0, 240),
                    'model2' => 'gpt-4o-mini',
                    'divergente2' => $divergente,
                    'scored2_at' => now(),
                ]);
                $ok++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('%d opinados · %d divergentes (revisar) · %d lotes com erro.', $ok, $divs, $erros));

        return self::SUCCESS;
    }
}
