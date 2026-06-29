<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * MESA DE PAUTA — Fase 4. Alerta no Telegram as pautas QUENTES e NOVAS do Radar
 * Cívico, agrupadas por ciclo (anti-flood). ADITIVO e ISOLADO: lê as tabelas do
 * radar + a tabela de dedup jr_civico_alertas, NÃO toca juiz/dispatcher/captura,
 * NÃO publica nada. Só MANDA UMA mensagem (digest) por ciclo.
 *
 * Gatilho de "quente" (QUALQUER um): score >= score_min · cidade prioritária com
 * score >= score_cidade · fonte-chave (MPSC/TCE) com score >= score_fonte_chave.
 * "Novo" = ato_ref ainda não registrado em jr_civico_alertas. Recência: só atos
 * pontuados nas últimas `janela_horas` (limita o backlog do 1º ciclo).
 *
 * SEGURANÇA DE CREDENCIAL: sem TELEGRAM_BOT_TOKEN + RADAR_CIVICO_ALERT_CHAT_ID o
 * comando NÃO envia — loga o que mandaria e sai (fail-closed). Nunca inventa alvo.
 *   --dry   : calcula e imprime o digest, nunca envia nem registra (teste)
 *   --seed  : marca todas as quentes atuais como já-alertadas SEM enviar (baseline
 *             pra quando for ligar de verdade, evita o flood do 1º disparo real)
 */
class JrCivicoAlertar extends Command
{
    protected $signature = 'jrcivico:alertar {--dry : só imprime, não envia nem registra} {--seed : marca as quentes atuais como alertadas sem enviar}';

    protected $description = 'Alerta no Telegram (bot do Gerador) as pautas quentes e novas do Radar Cívico, em digest por ciclo. Não publica nada; fail-closed sem credencial.';

    private const FONTES = [
        'dom' => 'jr_dom_atos',
        'camara' => 'jr_camara_proposicoes',
        'mpsc' => 'jr_mpsc_extratos',
        'tce' => 'jr_tce_decisoes',
    ];

    private const ICONE = ['dom' => '🧾', 'camara' => '📜', 'mpsc' => '⚖️', 'tce' => '💰', 'tjsc' => '👨‍⚖️'];

    public function handle(): int
    {
        $cfg = config('radar_civico.alertas');
        $quentes = $this->quentes($cfg);

        // já-alertados (dedup)
        $jaAlertado = DB::table('jr_civico_alertas')->pluck('ato_ref')->flip();
        $novas = array_values(array_filter($quentes, fn ($p) => ! isset($jaAlertado[$p['ato_ref']])));

        if ($this->option('seed')) {
            $this->registrar($novas);
            $this->info('Seed: ' . count($novas) . ' pauta(s) quente(s) marcada(s) como já-alertadas (sem enviar).');
            return self::SUCCESS;
        }

        if (empty($novas)) {
            $this->info('Nenhuma pauta quente nova neste ciclo.');
            return self::SUCCESS;
        }

        $msg = $this->montar($novas, $cfg['max_por_ciclo']);

        // fail-closed: sem credencial, não envia (documenta e sai)
        $token = (string) config('radar_civico.telegram.token');
        $chat = (string) config('radar_civico.telegram.chat_id');
        if ($this->option('dry') || $token === '' || $chat === '') {
            $motivo = $this->option('dry') ? '[--dry]' : '[SEM CREDENCIAL: configure TELEGRAM_BOT_TOKEN + RADAR_CIVICO_ALERT_CHAT_ID]';
            $this->warn("Não enviado {$motivo}. Mensagem que SERIA enviada (" . count($novas) . ' nova(s)):');
            $this->line(str_repeat('─', 48));
            $this->line($msg);
            $this->line(str_repeat('─', 48));
            return self::SUCCESS;
        }

        try {
            $resp = Http::asJson()->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            $this->error('Falha ao chamar a API do Telegram: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (! $resp->successful() || ! ($resp->json('ok') ?? false)) {
            $this->error('Telegram recusou: HTTP ' . $resp->status() . ' · ' . $resp->body());
            return self::FAILURE;
        }

        $this->registrar($novas);
        $this->info('Alerta enviado: ' . count($novas) . ' pauta(s) quente(s) nova(s).');
        return self::SUCCESS;
    }

    /** União das 4 fontes: pautas quentes E recentes (pontuadas na janela). */
    private function quentes(array $cfg): array
    {
        $limite = Carbon::now()->subHours(max(1, (int) $cfg['janela_horas']));
        $cidades = array_map('mb_strtolower', $cfg['cidades_prioritarias']);
        $fontesChave = array_map('strtolower', $cfg['fontes_chave']);

        $out = [];
        foreach (self::FONTES as $source => $tabela) {
            $rows = DB::table($tabela)
                ->whereNotNull('score_pauta')
                ->where('scored_at', '>=', $limite)
                ->get(['id', 'municipio', 'score_pauta', 'gancho_curto', 'gancho']);

            foreach ($rows as $a) {
                $score = (int) $a->score_pauta;
                $muni = (string) ($a->municipio ?? '');
                $motivo = null;
                if ($score >= $cfg['score_min']) {
                    $motivo = 'score';
                } elseif (in_array($source, $fontesChave, true) && $score >= $cfg['score_fonte_chave']) {
                    $motivo = 'fonte-chave';
                } elseif ($muni !== '' && in_array(mb_strtolower($muni), $cidades, true) && $score >= $cfg['score_cidade']) {
                    $motivo = 'cidade';
                }
                if ($motivo === null) {
                    continue;
                }

                $out[] = [
                    'ato_ref' => $source . ':' . $a->id,
                    'source' => $source,
                    'municipio' => $muni ?: '—',
                    'score' => $score,
                    'gancho' => (string) ($a->gancho_curto ?: $a->gancho ?: ''),
                    'motivo' => $motivo,
                ];
            }
        }

        usort($out, fn ($x, $y) => $y['score'] <=> $x['score']);
        return $out;
    }

    /** Monta o digest HTML (uma mensagem). */
    private function montar(array $novas, int $max): string
    {
        $base = rtrim((string) config('radar_civico.base_url'), '/');
        $n = count($novas);
        $mostra = array_slice($novas, 0, max(1, $max));

        $linhas = ["🛰️ <b>Radar Cívico</b> — {$n} pauta(s) quente(s) nova(s)", ''];
        foreach ($mostra as $p) {
            $ic = self::ICONE[$p['source']] ?? '•';
            $cidade = $this->esc($p['municipio']);
            $gancho = $this->esc(mb_substr($p['gancho'], 0, 160));
            $link = $base !== '' ? $base . '/radar-civico#ato-' . str_replace(':', '-', $p['ato_ref']) : '';
            $cab = "{$ic} <b>{$cidade}</b> · <code>{$p['score']}</code>";
            $linha = $gancho !== '' ? "{$cab}\n{$gancho}" : $cab;
            if ($link !== '') {
                $linha .= "\n<a href=\"{$link}\">abrir no radar ›</a>";
            }
            $linhas[] = $linha;
            $linhas[] = '';
        }
        if ($n > count($mostra)) {
            $linhas[] = '… e mais ' . ($n - count($mostra)) . ' no radar.';
        }

        return trim(implode("\n", $linhas));
    }

    private function registrar(array $novas): void
    {
        if (empty($novas)) {
            return;
        }
        $now = Carbon::now();
        $linhas = array_map(fn ($p) => [
            'ato_ref' => $p['ato_ref'],
            'source' => $p['source'],
            'score' => $p['score'],
            'motivo' => $p['motivo'],
            'alerted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $novas);

        foreach (array_chunk($linhas, 100) as $chunk) {
            DB::table('jr_civico_alertas')->insertOrIgnore($chunk);
        }
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
