<?php

namespace App\Console\Commands;

use App\Services\Jr\TceScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico FASE 5 — faro TCE: pontua processos/decisões ainda não pontuados
 * com Sonnet (dual-lens 🔴/🟢, lente TCE). ISOLADO do juiz/Opus.
 *
 * ⚖️ Multa/irregular só vira FATO se estiver no desfecho do TCE; o resto é LEAD.
 */
class JrTceScore extends Command
{
    protected $signature = 'jr:tce-score '
        . '{--limit=0 : Máx. a pontuar (0 = todos os pendentes)} '
        . '{--lote= : Processos por chamada (default config)} '
        . '{--model= : Modelo (default config — trocável)} '
        . '{--force : Re-pontua mesmo quem já tem score}';

    protected $description = 'Faro TCE: pontua processos/decisões do TCE-SC com Sonnet (noticiabilidade, dual-lens).';

    public function handle(): int
    {
        $cfg = config('tce.scoring');
        $lote = (int) ($this->option('lote') ?: $cfg['lote']);
        $cap = (int) $cfg['cap_chamadas'];
        $scorer = new TceScorer($this->option('model') ?: null);

        $q = DB::table('jr_tce_decisoes');
        if (! $this->option('force')) {
            $q->whereNull('score_pauta');
        }
        $limit = (int) $this->option('limit');
        $q->orderByDesc('data_pub')->orderByDesc('id');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $pendentes = $q->get(['id', 'tipo_proc', 'assunto', 'responsavel', 'unidade_gestora', 'desfecho', 'texto_bruto']);

        if ($pendentes->isEmpty()) {
            $this->info('Nada pra pontuar.');

            return self::SUCCESS;
        }

        $lotes = $pendentes->chunk($lote);
        $this->info(sprintf('Pontuando %d processos em %d chamadas (modelo %s, lote %d)…',
            $pendentes->count(), $lotes->count(), $scorer->modelo(), $lote));

        $bar = $this->output->createProgressBar($lotes->count());
        $bar->start();
        $ok = 0;
        $erros = 0;
        $chamadas = 0;
        foreach ($lotes as $chunk) {
            if ($chamadas >= $cap) {
                $this->newLine();
                $this->warn("Cap de {$cap} chamadas atingido — parando.");
                break;
            }
            $chamadas++;
            $itens = $chunk->map(fn ($r) => [
                'id' => $r->id,
                'tipo_proc' => $r->tipo_proc,
                'assunto' => $r->assunto,
                'responsavel' => $r->responsavel,
                'unidade_gestora' => $r->unidade_gestora,
                'desfecho' => $r->desfecho,
                'texto' => $r->texto_bruto,
            ])->all();

            try {
                $vereditos = $scorer->pontuarLote($itens);
                foreach ($vereditos as $rowId => $v) {
                    DB::table('jr_tce_decisoes')->where('id', $rowId)->update([
                        'score_pauta' => $v['score_pauta'],
                        'tipo' => $v['tipo'],
                        'objeto_limpo' => $v['objeto_limpo'],
                        'gancho_curto' => $v['gancho_curto'],
                        'gancho' => $v['gancho'],
                        'tipo_de_gancho' => $v['tipo_de_gancho'],
                        'o_que_apurar' => json_encode($v['o_que_apurar'], JSON_UNESCAPED_UNICODE),
                        'angulo_sugerido' => $v['angulo_sugerido'],
                        'flags' => json_encode($v['flags'], JSON_UNESCAPED_UNICODE),
                        'scored_model' => $scorer->modelo(),
                        'scored_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $ok++;
                }
            } catch (\Throwable $e) {
                $erros++;
                $this->newLine();
                $this->warn('Lote falhou: ' . mb_substr($e->getMessage(), 0, 160));
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Pontuados: %d OK, %d lotes com erro.', $ok, $erros));

        return self::SUCCESS;
    }
}
