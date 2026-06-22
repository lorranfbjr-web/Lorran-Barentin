<?php

namespace App\Console\Commands;

use App\Services\Jr\RevisorPosPublicacao;
use App\Services\Jr\WpControleClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PEÇA 4 — roda o revisor pós-post sobre os drafts. Lê o draft REAL via REST,
 * compara com o release original, audita contra o padrão JR e MARCA os que têm
 * pendência (bloco <!-- JR_REVISOR --> no topo do corpo + linha em
 * jr_pauta_revisao). Auto-fix só remove marcador de template vazado — nunca
 * reescreve fato. NUNCA publica (mantém draft).
 */
class JrPautaRevisor extends Command
{
    protected $signature = 'jrpauta:revisor '
        . '{--ids= : captura_message_ids (vírgula). Default: drafts de jr_pauta_publicacoes} '
        . '{--dry : audita e mostra, sem marcar nada no WP} '
        . '{--no-autofix : não remove marcador vazado automaticamente}';

    protected $description = 'Revisor pós-post: reaudita cada draft contra o padrão JR e marca os com pendência. Não publica.';

    private const MARK_REVISOR = 'JR_REVISOR';

    /** Padrões de marcador de TEMPLATE vazado no corpo visível (auto-fix seguro). */
    private const MARCADOR_RE = '/^.*(\[(?:COMMENT|TODO|INSTRU[ÇC][AÃ]O|PLACEHOLDER|LACUNA|NOTA)\b[^\]]*\]|\{\{[^}]+\}\}).*$/imu';

    public function handle(): int
    {
        $rev = new RevisorPosPublicacao();
        $wp = new WpControleClient();
        $dry = (bool) $this->option('dry');

        if (! $wp->whoAmI()['ok']) {
            $this->error('Auth WP falhou — confira JR_WP_* no .env.');

            return self::FAILURE;
        }

        $drafts = $this->selecionar();
        $this->info(sprintf('Drafts a auditar: %d  ·  modo=%s', $drafts->count(), $dry ? 'DRY' : 'REAL'));

        $resumo = [];
        foreach ($drafts as $d) {
            $this->newLine();
            $this->line('▸ #' . $d->wp_post_id . '  ' . mb_strimwidth((string) $d->titulo, 0, 56));

            $cap = DB::table('jr_pauta_capturas')->where('message_id', $d->captura_message_id)->first(['texto']);
            $post = $wp->getPost((int) $d->wp_post_id);
            $raw = (string) ($post['content']['raw'] ?? $wp->getRawContent((int) $d->wp_post_id));

            $temFoto = (int) ($post['featured_media'] ?? 0) > 0;
            $fotoCaption = $temFoto ? $wp->getMediaCaption((int) $post['featured_media']) : '';
            $corpoVisivel = $this->corpoVisivel($raw);

            $res = $rev->auditar([
                'titulo' => (string) $d->titulo,
                'corpo_visivel' => $corpoVisivel,
                'editoria' => (string) ($d->tema ?? 'geral'),
                'tem_foto' => $temFoto,
                'foto_caption' => $fotoCaption,
            ], (string) ($cap->texto ?? ''));

            $ic = $res['veredito'] === 'ok' ? '✅ OK' : '⚠️  REVISAR';
            $this->line('  ' . $ic . '  ($' . number_format((float) ($res['custo'] ?? 0), 4) . ')');
            foreach ($res['pendencias'] as $p) {
                $this->warn('    • ' . $p);
            }

            // ── AUTO-FIX seguro: remover marcador de template vazado ──
            $autoFix = false;
            $autoFixDesc = null;
            if (! $dry && ! $this->option('no-autofix') && preg_match(self::MARCADOR_RE, $corpoVisivel)) {
                $novo = $this->removerMarcadores($raw);
                if ($novo !== $raw) {
                    $wp->atualizarConteudo((int) $d->wp_post_id, $novo);
                    $raw = $novo;
                    $autoFix = true;
                    $autoFixDesc = 'removida(s) linha(s) com marcador de template vazado';
                    $this->line('  ↻ auto-fix: ' . $autoFixDesc);
                }
            }

            if (! $dry) {
                if ($res['veredito'] === 'revisar') {
                    $this->marcarNoCorpo($wp, (int) $d->wp_post_id, $raw, $res['pendencias']);
                }
                $this->registrar($d, $res, $autoFix, $autoFixDesc);
            }

            $resumo[] = ['id' => $d->wp_post_id, 'veredito' => $res['veredito'], 'n_pend' => count($res['pendencias']), 'autofix' => $autoFix];
        }

        $this->newLine();
        $this->line('===== RESUMO REVISOR =====');
        $ok = 0;
        foreach ($resumo as $x) {
            $this->line(sprintf('  #%s  %s  pendências=%d%s', $x['id'], strtoupper($x['veredito']), $x['n_pend'], $x['autofix'] ? '  (auto-fix)' : ''));
            $ok += $x['veredito'] === 'ok' ? 1 : 0;
        }
        $this->info(sprintf('OK: %d  ·  PARA REVISAR: %d', $ok, count($resumo) - $ok));

        return self::SUCCESS;
    }

    /** Texto visível: tira comentários HTML e tags, normaliza espaços. */
    private function corpoVisivel(string $raw): string
    {
        $semComentario = preg_replace('/<!--.*?-->/s', '', $raw);
        $texto = strip_tags((string) $semComentario);
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\n{3,}/', "\n\n", (string) $texto));
    }

    /** Remove linhas/parágrafos que contêm marcador de template no corpo visível. */
    private function removerMarcadores(string $raw): string
    {
        // opera por linha; preserva comentários HTML (notas internas legítimas).
        $linhas = preg_split('/\n/', $raw);
        $out = [];
        foreach ($linhas as $l) {
            $semComentario = preg_replace('/<!--.*?-->/s', '', $l);
            if (preg_match(self::MARCADOR_RE, (string) $semComentario)) {
                continue; // dropa a linha vazada
            }
            $out[] = $l;
        }

        return implode("\n", $out);
    }

    /** Prepend (idempotente) de um bloco JR_REVISOR no topo do corpo com as pendências. */
    private function marcarNoCorpo(WpControleClient $wp, int $postId, string $raw, array $pendencias): void
    {
        $raw = preg_replace('/<!--\s*' . self::MARK_REVISOR . '.*?-->\n?/s', '', $raw); // remove marca antiga
        $lista = $pendencias ? ('- ' . implode("\n- ", array_map(fn ($p) => str_replace(['-->', '<!--'], '', $p), $pendencias))) : '(sem detalhe)';
        $bloco = '<!-- ' . self::MARK_REVISOR . ' · revisar antes de publicar · ' . now()->toDateTimeString() . "\nPENDÊNCIAS:\n" . $lista . "\n-->\n";
        $wp->atualizarConteudo($postId, $bloco . ltrim($raw));
    }

    private function registrar($d, array $res, bool $autoFix, ?string $autoFixDesc): void
    {
        DB::table('jr_pauta_revisao')->insert([
            'wp_post_id' => $d->wp_post_id,
            'captura_message_id' => $d->captura_message_id,
            'veredito' => $res['veredito'],
            'pendencias' => json_encode($res['pendencias'], JSON_UNESCAPED_UNICODE),
            'achados' => json_encode($res['achados'], JSON_UNESCAPED_UNICODE),
            'auto_fix_aplicado' => $autoFix,
            'auto_fix_descricao' => $autoFixDesc,
            'modelo' => $res['modelo'],
            'custo_usd' => $res['custo'],
            'duration_ms' => $res['duration_ms'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function selecionar()
    {
        $q = DB::table('jr_pauta_publicacoes')->whereNotNull('wp_post_id');
        if ($ids = $this->option('ids')) {
            $q->whereIn('captura_message_id', array_map('trim', explode(',', $ids)));
        }

        return $q->orderBy('id')->get(['captura_message_id', 'wp_post_id', 'titulo', 'tema']);
    }
}
