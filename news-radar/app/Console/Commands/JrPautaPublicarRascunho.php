<?php

namespace App\Console\Commands;

use App\Services\Jr\JuizLlm;
use App\Services\Jr\PautaGate;
use App\Services\Jr\PautaReescritor;
use App\Services\Jr\WpControleClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ELO de publicação de pautas FRIAS: captura primária (prefeitura/órgão) →
 * gate duro → classificação fria (régua v3) → reescrita JR real → RASCUNHO
 * (draft) no WP de controle via REST. NUNCA publica (status sempre draft).
 *
 * Aditivo. Idempotente (jr_pauta_publicacoes.captura_message_id UNIQUE).
 * NÃO toca: prompt do juiz, prompt_versao, régua, scheduler, api.php, captura.
 */
class JrPautaPublicarRascunho extends Command
{
    protected $signature = 'jrpauta:publicar-rascunho '
        . '{--ids= : message_ids específicos (vírgula) — ignora a seleção automática} '
        . '{--limit=5 : teto de rascunhos por execução} '
        . '{--dry : classifica/reescreve e mostra, sem criar nada no WP}';

    protected $description = 'Cria RASCUNHOS (draft) no WP de controle a partir de capturas frias de fontes oficiais. Não publica.';

    /** Grupos PREFEITURA/ÓRGÃO aprovados (allowlist do teste). */
    private const ALLOWLIST = [
        'Prefeitura BC Imprensa', 'SECOM Itajaí - Imprensa', 'SECOM - Joinville',
        'Prefeitura de Itapema - Imprensa', 'Mídias TV / Rádio / Sites - Secom SC',
        'Imprensa - Prefeitura de São José', 'IMPRENSA - Pref. Tijucas',
        'Pref. Palhoça - Imprensa', 'Mídia - Proteção e Defesa Civil SC', 'Imprensa e PMF 2',
    ];

    public function handle(): int
    {
        $gate = new PautaGate();
        $reesc = new PautaReescritor(new JuizLlm());
        $wp = new WpControleClient();
        $dry = (bool) $this->option('dry');

        if (! $dry) {
            $me = $wp->whoAmI();
            if (! $me['ok']) {
                $this->error('Auth WP falhou — confira JR_WP_* no .env.');

                return self::FAILURE;
            }
            $this->info('Auth WP ok: user id ' . $me['id'] . ' (' . implode(',', $me['roles']) . ')');
        }

        $caps = $this->selecionar();
        $this->info(sprintf('Capturas a processar: %d  ·  modo=%s', $caps->count(), $dry ? 'DRY' : 'REAL (cria draft)'));

        $criados = [];
        foreach ($caps as $c) {
            $this->newLine();
            $this->line('▸ ' . $c->message_id . '  [' . $c->chat_name . ']');

            if (DB::table('jr_pauta_publicacoes')->where('captura_message_id', $c->message_id)->exists()) {
                $this->warn('  já processada — pula (idempotente).');
                continue;
            }

            // 1) GATE DURO
            [$g, $mot] = $gate->avaliar((string) $c->texto);
            if ($g !== PautaGate::OK) {
                $this->warn('  GATE: ' . $g . ' → fila humana. ' . $mot);
                $this->registrar($c, ['gate' => $g, 'gate_motivo' => $mot]);
                continue;
            }

            // 2) CLASSIFICAÇÃO (régua v3) — só FRIA segue
            $vd = $reesc->classificar((string) $c->texto, $c->fonte_cidade);
            if ($reesc->ganchoFilaHumana($vd)) {
                $this->warn('  gancho ' . $vd['tipo_gancho'] . ' → fila humana.');
                $this->registrar($c, ['gate' => PautaGate::FILA_SOLIDARIEDADE, 'gate_motivo' => 'gancho juiz: ' . $vd['tipo_gancho'], 'temperatura' => 'fila_humana', 'gancho' => $vd['tipo_gancho']]);
                continue;
            }
            if (! $reesc->ehFria($vd)) {
                $this->warn('  QUENTE (score ' . $vd['score_llm'] . ') → fora desta fase fria.');
                $this->registrar($c, ['gate' => 'descartado_quente', 'gate_motivo' => 'score ' . $vd['score_llm'], 'temperatura' => 'quente', 'gancho' => $vd['tipo_gancho']]);
                continue;
            }
            $this->line('  classif: score ' . $vd['score_llm'] . ' · FRIA · ' . $vd['escopo']);

            // 3) REESCRITA REAL
            $r = $reesc->reescrever((string) $c->texto, $vd['cidade'] ?? $c->fonte_cidade, (string) $c->chat_name);
            $this->line('  título: ' . $r['titulo']);

            if ($dry) {
                $this->line('  [DRY] não cria draft.');
                continue;
            }

            // 4) RASCUNHO no WP (draft)
            try {
                $wpres = $wp->criarRascunho($r, $c->message_id);
            } catch (\Throwable $e) {
                $this->error('  WP falhou: ' . $e->getMessage());
                continue;
            }

            $this->registrar($c, [
                'gate' => PautaGate::OK,
                'temperatura' => 'fria', 'gancho' => $vd['tipo_gancho'],
                'cidade' => $r['cidade'], 'tema' => $r['editoria'],
                'titulo' => $r['titulo'], 'linha_fina' => $r['linha_fina'],
                'materia' => $r['materia'], 'tags' => json_encode($r['tags'], JSON_UNESCAPED_UNICODE),
                'modelo' => $r['modelo'],
                'wp_post_id' => $wpres['id'], 'wp_status' => $wpres['status'], 'wp_edit_url' => $wpres['edit_url'],
            ]);

            $this->info('  ✅ draft #' . $wpres['id'] . ' (' . $wpres['status'] . ') → ' . $wpres['edit_url']);
            $criados[] = ['id' => $wpres['id'], 'titulo' => $r['titulo'], 'edit' => $wpres['edit_url'], 'captura' => $c->message_id, 'grupo' => $c->chat_name];

            if (count($criados) >= (int) $this->option('limit')) {
                break;
            }
        }

        $this->newLine();
        $this->info('RASCUNHOS CRIADOS: ' . count($criados));
        foreach ($criados as $x) {
            $this->line(sprintf('  #%d  %s  [%s]  %s', $x['id'], mb_strimwidth($x['titulo'], 0, 60), $x['grupo'], $x['edit']));
        }

        return self::SUCCESS;
    }

    private function selecionar()
    {
        $ids = $this->option('ids');
        $q = DB::table('jr_pauta_capturas')->where('tipo_conteudo', 'texto');

        if ($ids) {
            $q->whereIn('message_id', array_map('trim', explode(',', $ids)));
        } else {
            $q->where('fonte_tipo', 'imprensa_oficial')
                ->whereIn('chat_name', self::ALLOWLIST)
                ->whereRaw('LENGTH(texto) >= 250')
                ->whereNotIn('message_id', DB::table('jr_pauta_publicacoes')->select('captura_message_id'))
                ->orderByDesc('id')
                ->limit((int) $this->option('limit') * 3); // folga p/ descartes do gate/quente
        }

        return $q->get(['message_id', 'chat_name', 'fonte_cidade', 'texto']);
    }

    private function registrar($c, array $dados): void
    {
        DB::table('jr_pauta_publicacoes')->updateOrInsert(
            ['captura_message_id' => $c->message_id],
            array_merge($dados, ['processado_em' => now(), 'updated_at' => now(), 'created_at' => now()])
        );
    }
}
