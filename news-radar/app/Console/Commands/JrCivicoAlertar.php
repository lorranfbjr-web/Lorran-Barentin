<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MESA DE PAUTA — Fase 4. Alerta no WHATSAPP (grupo interno, canal SUGESTÕES —
 * config radar_civico.canais.sugestoes, instância de alerta JRLINK_ALERT_ZAPI_*)
 * as pautas QUENTES e NOVAS do Radar Cívico, agrupadas por ciclo (anti-flood).
 * ADITIVO e ISOLADO: lê as tabelas do radar + a tabela de dedup
 * jr_civico_alertas, NÃO toca juiz/dispatcher/captura, NÃO publica nada.
 * Só MANDA UMA mensagem (digest) por ciclo. Alerta é LEAD pra apurar, não
 * acusação (instauração ≠ condenação).
 *
 * BLOCO 2 (02/07): SAIU do Telegram — o bot do Telegram é 100% do Gerador→FB.
 * NUNCA enviar pela instância 276 (captura) nem 884 (disparador de publicação).
 *
 * Gatilho de "quente" (QUALQUER um): score >= score_min · cidade prioritária com
 * score >= score_cidade · fonte-chave (MPSC/TCE) com score >= score_fonte_chave.
 * "Novo" = ato_ref ainda não registrado em jr_civico_alertas. Recência (BLOCO 1):
 * data_pub nos últimos `alert_dias`, nunca futura nem suspeita.
 *
 * SEGURANÇA DE CREDENCIAL: sem instância de alerta + grupo configurados o
 * comando NÃO envia — loga o que mandaria e sai (fail-closed). Nunca inventa alvo.
 *   --dry   : calcula e imprime o digest, nunca envia nem registra (teste)
 *   --seed  : marca todas as quentes atuais como já-alertadas SEM enviar (baseline
 *             OBRIGATÓRIO ao ligar/religar fonte — backfill novo nunca flooda)
 */
class JrCivicoAlertar extends Command
{
    protected $signature = 'jrcivico:alertar {--dry : só imprime, não envia nem registra} {--seed : marca as quentes atuais como alertadas sem enviar}';

    protected $description = 'Alerta no WhatsApp (grupo interno, instância de alerta) as pautas quentes e novas do Radar Cívico, em digest por ciclo. Não publica nada; fail-closed sem credencial.';

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

        // fail-closed: sem instância de alerta + grupo, não envia (documenta e sai)
        $zap = new \App\Services\Jr\ZapRascunhos();
        $grupo = (string) config('radar_civico.canais.sugestoes');
        if ($this->option('dry') || ! $zap->configurado() || $grupo === '') {
            $motivo = $this->option('dry') ? '[--dry]' : '[SEM CREDENCIAL: configure JRLINK_ALERT_ZAPI_* + RADAR_CIVICO_SUGESTOES_GROUP/JRLINK_RASCUNHOS_GROUP]';
            $this->warn("Não enviado {$motivo}. Mensagem que SERIA enviada (" . count($novas) . ' nova(s)):');
            $this->line(str_repeat('─', 48));
            $this->line($msg);
            $this->line(str_repeat('─', 48));
            return self::SUCCESS;
        }

        $messageId = $zap->texto($msg, $grupo);
        if ($messageId === null) {
            $this->error('Z-API recusou o envio (ver laravel.log) — nada registrado, tenta no próximo ciclo.');
            return self::FAILURE;
        }

        $this->registrar($novas);
        $this->info('Alerta enviado: ' . count($novas) . ' pauta(s) quente(s) nova(s).');
        return self::SUCCESS;
    }

    /**
     * União das 4 fontes: pautas quentes E recentes DE VERDADE.
     *
     * BLOCO 1 (Goal 02/07): a janela é por DATA DE PUBLICAÇÃO (`data_pub`),
     * não mais por `scored_at` — um backfill que pontua item de 2025 hoje NÃO
     * alerta mais. Regras: data_pub nos últimos `alert_dias` (default 7d),
     * nada de futuro, nada com data_suspeita=1, nada sem data_pub (esses só
     * aparecem na página, seção Arquivo). Mesma verdade de recência da
     * exibição (Recencia/config).
     */
    private function quentes(array $cfg): array
    {
        $dias = max(1, (int) ($cfg['alert_dias'] ?? 7));
        $corte = Carbon::now()->subDays($dias)->toDateString();
        $hoje = Carbon::now()->toDateString();
        $cidades = array_map('mb_strtolower', $cfg['cidades_prioritarias']);
        $fontesChave = array_map('strtolower', $cfg['fontes_chave']);

        $out = [];
        foreach (self::FONTES as $source => $tabela) {
            $rows = DB::table($tabela)
                ->whereNotNull('score_pauta')
                ->whereNotNull('data_pub')
                ->where('data_pub', '>=', $corte)
                ->where('data_pub', '<=', $hoje)
                ->where(fn ($q) => $q->whereNull('data_suspeita')->orWhere('data_suspeita', '!=', 1))
                ->get(['id', 'municipio', 'score_pauta', 'gancho_curto', 'gancho', 'data_pub', 'objeto_limpo', 'url_fonte']);

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
                    'objeto' => (string) ($a->objeto_limpo ?? ''),
                    'url_fonte' => (string) ($a->url_fonte ?? ''),
                    'motivo' => $motivo,
                    'data_pub' => (string) $a->data_pub,
                ];
            }
        }

        usort($out, fn ($x, $y) => $y['score'] <=> $x['score']);
        return $out;
    }

    /**
     * Monta o digest em TEXTO WhatsApp (2d do goal 02/07, mobile/escaneável):
     * NOTA · FONTE(ícone) · CIDADE(REGIÃO) — O QUE É — POR QUE VIRA PAUTA —
     * link "ver na fonte". Sem HTML, sem token/telefone. ZapRascunhos fatia
     * >4000 chars.
     */
    private function montar(array $novas, int $max): string
    {
        $base = rtrim((string) config('radar_civico.base_url'), '/');
        $n = count($novas);
        $mostra = array_slice($novas, 0, max(1, $max));

        $linhas = ["🛰️ *Radar Cívico* — {$n} pauta(s) quente(s) nova(s)", '_Lead pra apurar, não acusação._', ''];
        foreach ($mostra as $p) {
            $ic = self::ICONE[$p['source']] ?? '•';
            $tier = \App\Services\Jr\CidadesInteresse::tier($p['municipio']);
            $regiao = $tier === 1 ? ' ⭐' : ($tier === 2 ? ' (região)' : '');
            $oque = mb_substr(trim($p['objeto']), 0, 140);
            $porque = mb_substr(trim($p['gancho']), 0, 160);
            $link = $p['url_fonte'] !== ''
                ? $p['url_fonte']
                : ($base !== '' ? $base . '/radar-civico#ato-' . str_replace(':', '-', $p['ato_ref']) : '');

            $linha = "*{$p['score']}* · {$ic} " . strtoupper($p['source']) . " · *{$p['municipio']}*{$regiao}";
            if ($oque !== '') {
                $linha .= "\n{$oque}";
            }
            if ($porque !== '' && mb_strtolower($porque) !== mb_strtolower($oque)) {
                $linha .= "\n_{$porque}_";
            }
            if ($link !== '') {
                $linha .= "\n🔗 ver na fonte: {$link}";
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
