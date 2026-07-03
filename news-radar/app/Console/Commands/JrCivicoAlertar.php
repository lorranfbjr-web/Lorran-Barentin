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
        'prefeitura' => 'jr_prefeitura_noticias', // BLOCO 3: release oficial (🟢 serviço/1ª-mão)
    ];

    private const ICONE = ['dom' => '🧾', 'camara' => '📜', 'mpsc' => '⚖️', 'tce' => '💰', 'tjsc' => '👨‍⚖️', 'prefeitura' => '📣'];

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

        // BLOCO 0b (03/07): janela de silêncio (hora LOCAL). Não envia e NÃO
        // registra — o dedup só marca no envio, então as pautas ACUMULAM e o
        // 1º ciclo depois do silêncio manda o digest único. --dry passa reto
        // (é teste, não acorda ninguém).
        if (! $this->option('dry') && $this->emSilencio($cfg)) {
            $this->info('Silêncio (' . $cfg['silencio'] . ' local): ' . count($novas)
                . ' pauta(s) acumulada(s) pro digest único pós-silêncio. Nada enviado, nada registrado.');
            return self::SUCCESS;
        }

        $msg = $this->montar($novas, $cfg);

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
     * BLOCO 0b (03/07): dentro da janela de silêncio (hora LOCAL, formato
     * "HH-HH"; "22-06" cruza a meia-noite) o alerta não envia nem registra.
     * Faixa inválida/vazia = sem silêncio (fail-open: alerta é o produto).
     */
    private function emSilencio(array $cfg): bool
    {
        $faixa = trim((string) ($cfg['silencio'] ?? ''));
        if ($faixa === '' || ! preg_match('/^(\d{1,2})-(\d{1,2})$/', $faixa, $m)) {
            return false;
        }
        $ini = min(23, (int) $m[1]);
        $fim = min(23, (int) $m[2]);
        if ($ini === $fim) {
            return false; // faixa nula (ex.: "8-8") = silêncio desligado
        }
        $h = (int) Carbon::now((string) ($cfg['tz_local'] ?? 'America/Sao_Paulo'))->format('G');

        return $ini < $fim ? ($h >= $ini && $h < $fim) : ($h >= $ini || $h < $fim);
    }

    /**
     * Monta o digest em TEXTO WhatsApp (2d do goal 02/07, mobile/escaneável).
     * BLOCO 0b (03/07): itens do run AGRUPADOS POR CIDADE; teto de detalhadas
     * (`max_por_ciclo`) e cap de mensagens por run (`max_msg_run` — nº de
     * fatias de 4000 chars do ZapRascunhos). O que não coube em detalhe vira
     * linha compacta de digest; se nem as compactas couberem, "… e mais N".
     * Nenhum item some sem ao menos ser contado.
     */
    private function montar(array $novas, array $cfg): string
    {
        $base = rtrim((string) config('radar_civico.base_url'), '/');
        $n = count($novas);
        $max = max(1, (int) ($cfg['max_por_ciclo'] ?? 12));
        $capChars = 4000 * max(1, (int) ($cfg['max_msg_run'] ?? 3));

        // $novas já vem ordenado por score desc; detalha o topo, resume o resto
        for ($det = min($max, $n); $det >= 1; $det--) {
            $msg = $this->render($novas, $det, $base, $capChars);
            if (mb_strlen($msg) <= $capChars) {
                return $msg;
            }
        }

        return mb_substr($this->render($novas, 1, $base, $capChars), 0, $capChars);
    }

    private function render(array $novas, int $det, string $base, int $capChars): string
    {
        $n = count($novas);
        $detalhadas = array_slice($novas, 0, $det);
        $resto = array_slice($novas, $det);

        // agrupa as detalhadas por cidade (ordem: melhor score da cidade primeiro)
        $porCidade = [];
        foreach ($detalhadas as $p) {
            $porCidade[$p['municipio']][] = $p;
        }

        $linhas = ["🛰️ *Radar Cívico* — {$n} pauta(s) quente(s) nova(s)", '_Lead pra apurar, não acusação._', ''];
        foreach ($porCidade as $cidade => $itens) {
            $tier = \App\Services\Jr\CidadesInteresse::tier($cidade);
            $regiao = $tier === 1 ? ' ⭐' : ($tier === 2 ? ' (região)' : '');
            $linhas[] = "📍 *{$cidade}*{$regiao}";
            foreach ($itens as $p) {
                $ic = self::ICONE[$p['source']] ?? '•';
                $oque = mb_substr(trim($p['objeto']), 0, 140);
                $porque = mb_substr(trim($p['gancho']), 0, 160);
                $link = $p['url_fonte'] !== ''
                    ? $p['url_fonte']
                    : ($base !== '' ? $base . '/radar-civico#ato-' . str_replace(':', '-', $p['ato_ref']) : '');

                $linha = "*{$p['score']}* · {$ic} " . strtoupper($p['source']);
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
        }

        // excedente do cap: digest compacto (1 linha por item), aparado se preciso
        if (! empty($resto)) {
            $linhas[] = '▫️ *E mais no radar:*';
            $corpo = mb_strlen(implode("\n", $linhas));
            $sobraram = 0;
            foreach ($resto as $p) {
                $ic = self::ICONE[$p['source']] ?? '•';
                $compacta = "• *{$p['score']}* {$ic} {$p['municipio']} — " . mb_substr(trim($p['objeto']), 0, 60);
                if ($corpo + mb_strlen($compacta) + 80 > $capChars) { // 80 = folga do rodapé
                    $sobraram++;
                    continue;
                }
                $linhas[] = $compacta;
                $corpo += mb_strlen($compacta) + 1;
            }
            if ($sobraram > 0) {
                $linhas[] = '… e mais ' . $sobraram . ' no radar' . ($base !== '' ? ': ' . $base . '/radar-civico' : '.');
            }
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
