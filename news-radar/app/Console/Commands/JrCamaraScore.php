<?php

namespace App\Console\Commands;

use App\Services\Jr\CamaraScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico FASE 2 — faro CÂMARA: roda o CamaraScorer (Sonnet, dual-lens
 * 🔴/🟢, lente câmara) sobre as proposições ainda não pontuadas e grava
 * noticiabilidade, gancho, o-que-apurar e flags. ISOLADO do juiz/Opus.
 *
 * ⚖️ Pontua como EDITOR. Saída = LEAD pra apurar, nunca acusação.
 */
class JrCamaraScore extends Command
{
    protected $signature = 'jr:camara-score '
        . '{--limit=0 : Máx. de proposições a pontuar (0 = todas as pendentes)} '
        . '{--lote= : Proposições por chamada LLM (default config)} '
        . '{--model= : Modelo do faro (default config — trocável)} '
        . '{--force : Re-pontua mesmo quem já tem score}';

    protected $description = 'Faro câmara: pontua proposições (SAPL) com Sonnet (noticiabilidade, dual-lens).';

    public function handle(): int
    {
        $cfg = config('camara.scoring');
        $lote = (int) ($this->option('lote') ?: $cfg['lote']);
        $cap = (int) $cfg['cap_chamadas'];
        $scorer = new CamaraScorer($this->option('model') ?: null);

        $q = DB::table('jr_camara_proposicoes');
        if (! $this->option('force')) {
            $q->whereNull('score_pauta');
        }
        $limit = (int) $this->option('limit');
        $q->orderByDesc('data_pub')->orderByDesc('materia_id');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $pendentes = $q->get(['id', 'materia_id', 'municipio', 'tipo_descricao', 'complementar', 'numero', 'ano', 'autores', 'ementa', 'texto_bruto']);

        if ($pendentes->isEmpty()) {
            $this->info('Nada pra pontuar.');

            return self::SUCCESS;
        }

        $lotes = $pendentes->chunk($lote);
        $this->info(sprintf('Pontuando %d proposições em %d chamadas (modelo %s, lote %d)…',
            $pendentes->count(), $lotes->count(), $scorer->modelo(), $lote));

        $bar = $this->output->createProgressBar($lotes->count());
        $bar->start();
        $t0 = microtime(true);
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
            // usa a PK da linha como id do faro (materia_id não é único entre câmaras)
            $itens = $chunk->map(fn ($r) => [
                'materia_id' => $r->id,
                'municipio' => $r->municipio,
                'tipo_descricao' => $r->tipo_descricao,
                'complementar' => $r->complementar,
                'numero' => $r->numero,
                'ano' => $r->ano,
                'autores' => $r->autores,
                'ementa' => $r->ementa,
                'texto' => $r->texto_bruto,
            ])->all();

            try {
                $vereditos = $scorer->pontuarLote($itens);
                foreach ($vereditos as $rowId => $v) {
                    DB::table('jr_camara_proposicoes')->where('id', $rowId)->update([
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
        $this->info(sprintf('Pontuadas: %d OK, %d lotes com erro.', $ok, $erros));

        // B5 — observabilidade: uma linha por ciclo (o watchdog lê daqui)
        \App\Services\Jr\CivicoScoringLog::registrar('camara', [
            'chamadas' => $chamadas,
            'scorados' => $ok,
            'falhas' => $erros,
            'pendentes_apos' => DB::table('jr_camara_proposicoes')->whereNull('score_pauta')->count(),
            'modelo' => $scorer->modelo(),
            'duracao_ms' => (int) ((microtime(true) - $t0) * 1000),
        ]);

        return self::SUCCESS;
    }
}
