<?php

namespace App\Console\Commands;

use App\Services\Jr\JuizVisualFoto;
use App\Services\Jr\PautaMidia;
use App\Services\Jr\WpControleClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PEÇA 2 + 3 — juiz visual escolhe a melhor foto (ou og:image de fallback) e
 * seta como destacada no draft, com crédito. NUNCA publica (post fica draft).
 *
 * Idempotente: pula draft que já tem foto destacada setada por este elo
 * (jr_pauta_midia.wp_media_id), salvo --forcar.
 */
class JrPautaFotoCapa extends Command
{
    protected $signature = 'jrpauta:foto-capa '
        . '{--ids= : captura_message_ids (vírgula). Default: drafts de jr_pauta_publicacoes} '
        . '{--dry : roda o juiz visual e mostra a escolha, sem subir nada no WP} '
        . '{--forcar : reavalia mesmo já tendo foto setada}';

    protected $description = 'Escolhe a foto de capa (juiz visual Opus / og:image) e seta como destacada no draft, com crédito. Não publica.';

    /** Marcador da linha de crédito no corpo (idempotência). */
    private const MARK_CREDITO = '<!-- JR_FOTO_CREDITO -->';

    public function handle(): int
    {
        $juiz = new JuizVisualFoto();
        $midiaSvc = new PautaMidia();
        $wp = new WpControleClient();
        $dry = (bool) $this->option('dry');

        if (! $dry && ! $wp->whoAmI()['ok']) {
            $this->error('Auth WP falhou — confira JR_WP_* no .env.');

            return self::FAILURE;
        }

        $drafts = $this->selecionar();
        $this->info(sprintf('Drafts a processar: %d  ·  modo=%s', $drafts->count(), $dry ? 'DRY' : 'REAL'));

        $resumo = [];
        foreach ($drafts as $d) {
            $this->newLine();
            $this->line('▸ draft #' . $d->wp_post_id . '  ' . mb_strimwidth((string) $d->titulo, 0, 56) . '  [' . $d->cidade . ']');

            $jaTem = DB::table('jr_pauta_midia')->where('captura_message_id', $d->captura_message_id)
                ->whereNotNull('wp_media_id')->exists();
            if ($jaTem && ! $this->option('forcar')) {
                $this->warn('  já tem foto setada por este elo — pula (use --forcar).');
                $resumo[] = ['id' => $d->wp_post_id, 'via' => 'já tinha', 'credito' => null];
                continue;
            }

            $cap = DB::table('jr_pauta_capturas')->where('message_id', $d->captura_message_id)->first();
            $rows = DB::table('jr_pauta_midia')->where('captura_message_id', $d->captura_message_id)
                ->where('download_status', 'ok')->get();

            $escolhidaRow = null;
            $credito = null;
            $via = null;
            $just = null;

            // ── PEÇA 2: juiz visual sobre as candidatas do WhatsApp ──
            if ($rows->isNotEmpty()) {
                $fotos = $rows->map(fn ($r) => [
                    'id' => $r->midia_message_id,
                    'path' => storage_path('app/' . $r->arquivo_path),
                    'credito' => $r->credito,
                ])->all();
                $res = $juiz->escolher($fotos, (string) $d->titulo, (string) $cap->texto);
                $this->line('  juiz visual: ' . ($res['escolhida_id'] ? 'escolheu ' . $res['escolhida_id'] : 'NENHUMA serve') . '  ($' . number_format((float) $res['custo'], 4) . ')');
                if ($res['justificativa']) {
                    $this->line('    → ' . mb_strimwidth($res['justificativa'], 0, 100));
                }
                // grava avaliações em todas as candidatas
                foreach ($rows as $r) {
                    $esc = $res['escolhida_id'] === $r->midia_message_id;
                    DB::table('jr_pauta_midia')->where('id', $r->id)->update([
                        'escolhida' => $esc,
                        'juiz_justificativa' => $esc ? $res['justificativa'] : null,
                        'updated_at' => now(),
                    ]);
                }
                if ($res['escolhida_id']) {
                    $escolhidaRow = $rows->firstWhere('midia_message_id', $res['escolhida_id']);
                    $credito = $res['credito'] ?? $escolhidaRow->credito;
                    $via = 'whatsapp';
                    $just = $res['justificativa'];
                }
            } else {
                $this->line('  sem candidatas do WhatsApp.');
            }

            // ── PEÇA 2b: fallback og:image ──
            if (! $escolhidaRow) {
                $og = $juiz->fallbackOgImage((string) $cap->texto);
                if ($og) {
                    $this->line('  fallback og:image: ' . mb_strimwidth($og['og_image'], 0, 70));
                    $dest = 'pauta-midia/' . $d->captura_message_id . '/ogimage.jpg';
                    $dl = $midiaSvc->baixar($og['og_image'], $dest);
                    if ($dl['ok']) {
                        DB::table('jr_pauta_midia')->updateOrInsert(
                            ['midia_message_id' => 'og:' . $d->captura_message_id],
                            [
                                'captura_message_id' => $d->captura_message_id,
                                'chat_name' => $cap->chat_name, 'origem' => 'og_image',
                                'mime_type' => 'image/jpeg', 'caption' => null,
                                'credito' => $og['credito'], 'arquivo_path' => $dest,
                                'source_url' => mb_substr($og['og_image'], 0, 1024),
                                'download_status' => 'ok', 'bytes' => $dl['bytes'],
                                'escolhida' => true, 'juiz_justificativa' => 'og:image do link oficial (sem foto no WhatsApp)',
                                'updated_at' => now(), 'created_at' => now(),
                            ]
                        );
                        $escolhidaRow = DB::table('jr_pauta_midia')->where('midia_message_id', 'og:' . $d->captura_message_id)->first();
                        $credito = $og['credito'];
                        $via = 'og:image';
                        $just = 'og:image do link oficial';
                    } else {
                        $this->warn('  og:image não baixou: ' . $dl['error']);
                    }
                } else {
                    $this->warn('  sem og:image utilizável (sem link oficial ou domínio bloqueia bot).');
                }
            }

            if (! $escolhidaRow) {
                $this->warn('  ⚠️  ficou SEM foto — anotar no relatório.');
                $resumo[] = ['id' => $d->wp_post_id, 'via' => 'NENHUMA', 'credito' => null, 'just' => 'sem foto recuperável'];
                continue;
            }

            if ($dry) {
                $this->line('  [DRY] escolhida=' . $escolhidaRow->midia_message_id . ' via=' . $via . ' cred=' . $credito);
                $resumo[] = ['id' => $d->wp_post_id, 'via' => $via, 'credito' => $credito, 'just' => $just];
                continue;
            }

            // ── PEÇA 3: sobe a foto + seta destacada + crédito no corpo ──
            try {
                $abs = storage_path('app/' . $escolhidaRow->arquivo_path);
                $up = $wp->uploadMidia($abs, 'jr-' . $d->wp_post_id . '-capa.jpg', (string) $credito, (string) $d->titulo);
                $wp->setFeaturedMedia((int) $d->wp_post_id, $up['id']);
                $this->inserirCreditoCorpo($wp, (int) $d->wp_post_id, (string) $credito);

                DB::table('jr_pauta_midia')->where('id', $escolhidaRow->id)->update([
                    'wp_media_id' => $up['id'], 'escolhida' => true, 'updated_at' => now(),
                ]);
                $this->info('  ✅ media #' . $up['id'] . ' → featured de #' . $d->wp_post_id . '  (via ' . $via . ', cred: ' . $credito . ')');
                $resumo[] = ['id' => $d->wp_post_id, 'via' => $via, 'credito' => $credito, 'media' => $up['id'], 'just' => $just];
            } catch (\Throwable $e) {
                $this->error('  WP falhou: ' . $e->getMessage());
                $resumo[] = ['id' => $d->wp_post_id, 'via' => $via . ' (erro WP)', 'credito' => $credito];
            }
        }

        $this->newLine();
        $this->line('===== RESUMO FOTO DE CAPA =====');
        foreach ($resumo as $x) {
            $this->line(sprintf('  #%s  via=%-10s  cred=%s', $x['id'], $x['via'], $x['credito'] ?? '—'));
        }

        return self::SUCCESS;
    }

    /** Insere (uma vez) a linha de crédito da foto no fim do corpo do draft. */
    private function inserirCreditoCorpo(WpControleClient $wp, int $postId, string $credito): void
    {
        if ($credito === '') {
            return;
        }
        $raw = $wp->getRawContent($postId);
        if (str_contains($raw, self::MARK_CREDITO)) {
            return; // já tem
        }
        $linha = self::MARK_CREDITO . "\n<p><em>Foto: " . e($credito) . "</em></p>";
        $wp->atualizarConteudo($postId, rtrim($raw) . "\n" . $linha);
    }

    private function selecionar()
    {
        $q = DB::table('jr_pauta_publicacoes')->whereNotNull('wp_post_id');
        if ($ids = $this->option('ids')) {
            $q->whereIn('captura_message_id', array_map('trim', explode(',', $ids)));
        }

        return $q->orderBy('id')->get(['captura_message_id', 'wp_post_id', 'titulo', 'cidade', 'tema']);
    }
}
