<?php

namespace App\Console\Commands;

use App\Services\Jr\PrefeituraScorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BLOCO 3 (Goal 02/07) — faro PREFEITURA: roda o PrefeituraScorer (Sonnet,
 * lente 🟢 serviço/1ª-mão) sobre as notícias institucionais ainda não
 * pontuadas. Release é VERSÃO oficial — a saída sinaliza isso. ISOLADO do juiz.
 */
class JrPrefeituraScore extends Command
{
    protected $signature = 'jr:prefeitura-score '
        . '{--limit=0 : Máx. de notícias a pontuar (0 = todas as pendentes)} '
        . '{--lote=8 : Notícias por chamada LLM} '
        . '{--model= : Modelo do faro (default config)} '
        . '{--force : Re-pontua mesmo quem já tem score}';

    protected $description = 'Faro prefeitura: pontua notícias institucionais com Sonnet (lente serviço/1ª-mão).';

    public function handle(): int
    {
        $lote = max(1, (int) $this->option('lote'));
        $scorer = new PrefeituraScorer($this->option('model') ?: null);

        $q = DB::table('jr_prefeitura_noticias');
        if (! $this->option('force')) {
            $q->whereNull('score_pauta');
        }
        $limit = (int) $this->option('limit');
        $q->orderByDesc('data_pub')->orderByDesc('id');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $pendentes = $q->get(['id', 'municipio', 'titulo', 'objeto', 'texto_bruto', 'data_pub']);

        if ($pendentes->isEmpty()) {
            $this->info('Nada pra pontuar.');

            return self::SUCCESS;
        }

        $lotes = $pendentes->chunk($lote);
        $this->info(sprintf('Pontuando %d notícias em %d chamadas (modelo %s, lote %d)…',
            $pendentes->count(), $lotes->count(), $scorer->modelo(), $lote));

        $t0 = microtime(true);
        $ok = 0;
        $erros = 0;
        $chamadas = 0;
        foreach ($lotes as $chunk) {
            $chamadas++;
            $itens = $chunk->map(fn ($r) => [
                'ato_id' => $r->id,
                'municipio' => $r->municipio,
                'titulo' => $r->titulo,
                'texto' => $r->texto_bruto ?: $r->objeto,
                'data_pub' => $r->data_pub,
            ])->all();

            try {
                $vereditos = $scorer->pontuarLote($itens);
                foreach ($vereditos as $rowId => $v) {
                    DB::table('jr_prefeitura_noticias')->where('id', $rowId)->update([
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
                $this->warn('Lote falhou: ' . mb_substr($e->getMessage(), 0, 160));
            }
        }
        $this->info(sprintf('Pontuadas: %d OK, %d lotes com erro.', $ok, $erros));

        // observabilidade: mesmo log de ciclo dos outros faros (watchdog lê daqui)
        \App\Services\Jr\CivicoScoringLog::registrar('prefeitura', [
            'chamadas' => $chamadas,
            'scorados' => $ok,
            'falhas' => $erros,
            'pendentes_apos' => DB::table('jr_prefeitura_noticias')->whereNull('score_pauta')->count(),
            'modelo' => $scorer->modelo(),
            'duracao_ms' => (int) ((microtime(true) - $t0) * 1000),
        ]);

        return self::SUCCESS;
    }
}
