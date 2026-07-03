<?php

namespace App\Console\Commands;

use App\Services\Jr\CidadesInteresse;
use App\Services\Jr\FotoOficial;
use App\Services\Jr\JanelaSilencio;
use App\Services\Jr\RascunhoCivico;
use App\Services\Jr\TellCheck;
use App\Services\Jr\ZapRascunhos;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BLOCO 1 (03/07) — FLYWHEEL SALTO 1: auto-rascunho SELETIVO.
 *
 * Pega release 🟢 de prefeitura (serviço/1ª-mão) que passou no gate
 * risco×confiança e entrega um rascunho padrão JR pronto no grupo RASCUNHOS.
 * Foto: SÓ a oficial da página da fonte (FotoOficial, 8b) com crédito do
 * órgão — foto de WhatsApp/captura continua FORA (Trava #0 intocada).
 *
 * GATE (E-lógico, tudo em config radar_civico.auto_rascunho):
 *   fonte = jr_prefeitura_noticias (única 🟢 1ª-mão; DOM/câmara/MPSC/TCE
 *   NUNCA) · score >= score_min · cidade tier1 · data_pub <= frescor_horas ·
 *   categoria baixo risco (allowlist) E zero palavra vermelha (blocklist
 *   VENCE: fiscalização/polícia/morte/judicial/político nunca vira auto).
 *
 * GUARD-RAILS: cap diário (max_dia, dia LOCAL), dedup por ato_ref em
 * jr_rascunho_entregas, janela de silêncio do Bloco 0b, fail-closed sem
 * credencial Z-API (loga e sai). NÃO publica nada — rascunho é rascunho.
 *
 * BLOCO 8 (03/07, feedback do Lorran): a mensagem do grupo é SÓ conteúdo
 * publicável — foto oficial anexada (caption = título) + título/linha fina/
 * corpo + crédito da foto como última linha, e depois do separador UMA linha
 * operacional. [AUTO]/score/gate/checklist ficam no BANCO (gate_motivo,
 * payload) e na Mesa (jr_pauta_fila.rascunho) — nunca no grupo.
 *   --dry : avalia o gate e imprime o que faria, sem LLM, sem envio
 *   --reenviar= : "ultimo" ou ato_ref — reenvia entrega existente no formato
 *                 novo (prova real 8d): atualiza message_id, não conta no cap
 */
class JrCivicoAutoRascunho extends Command
{
    protected $signature = 'jrcivico:auto-rascunho {--dry : avalia o gate e imprime, não gera nem envia} {--max= : teto extra deste run (nunca passa do cap diário)} {--reenviar= : reenvia entrega existente no formato limpo (ultimo | ato_ref)} {--regen : no reenviar, ignora o payload salvo e regenera via LLM}';

    protected $description = 'Auto-rascunho seletivo: release 🟢 de prefeitura que passa no gate risco×confiança vira rascunho [AUTO] no grupo RASCUNHOS. Cap diário, dedup, silêncio, fail-closed.';

    public function handle(): int
    {
        $cfg = config('radar_civico.auto_rascunho');
        $dry = (bool) $this->option('dry');

        if (! $dry && JanelaSilencio::ativa(config('radar_civico.alertas'))) {
            $this->info('Silêncio: auto-rascunho espera o dia acordar.');

            return self::SUCCESS;
        }

        if (($ref = trim((string) $this->option('reenviar'))) !== '') {
            return $this->reenviar($ref);
        }

        // cap diário em dia LOCAL (o grupo vive em UTC-3)
        $tz = (string) config('radar_civico.alertas.tz_local', 'America/Sao_Paulo');
        $inicioDiaUtc = Carbon::now($tz)->startOfDay()->setTimezone('UTC');
        $hoje = DB::table('jr_rascunho_entregas')
            ->where('tipo', 'auto')
            ->where('created_at', '>=', $inicioDiaUtc)
            ->count();
        $restante = max(0, (int) $cfg['max_dia'] - $hoje);
        if ($restante === 0) {
            $this->info("Cap diário atingido ({$cfg['max_dia']}/dia) — nada a fazer.");

            return self::SUCCESS;
        }

        $candidatos = $this->candidatos($cfg);
        if (empty($candidatos)) {
            $this->info('Nenhum release passou no gate neste ciclo.');

            return self::SUCCESS;
        }

        $zap = new ZapRascunhos;
        $grupo = (string) config('radar_civico.canais.rascunhos');
        if (($max = (int) $this->option('max')) > 0) {
            $restante = min($restante, $max);
        }
        $enviaveis = array_slice($candidatos, 0, $restante);
        $this->info(count($candidatos).' no gate, '.count($enviaveis)." dentro do cap (restavam {$restante}).");

        foreach ($enviaveis as $c) {
            if ($dry) {
                $this->line("[dry] GERARIA {$c['ato_ref']} — {$c['gate_motivo']}");

                continue;
            }

            $rc = app(RascunhoCivico::class);
            $r = $rc->gerar($c['ato_ref']);
            if (empty($r)) {
                $this->warn("LLM falhou em {$c['ato_ref']} — pulo (tenta no próximo ciclo).");

                continue;
            }

            // O5 — tell-check SEMPRE (heurística + cético), NUNCA segura/descarta.
            $tellFlag = $this->tellCheck($rc, $c['ato_ref'], $r);

            if ($this->entregar($zap, $grupo, $rc, $r, $c, tellFlag: $tellFlag) === null) {
                continue;
            }
        }

        return self::SUCCESS;
    }

    /**
     * BLOCO 8 — entrega no formato LIMPO: foto oficial anexada (caption =
     * título) + texto publicável + 1 linha operacional. Registra entrega
     * (payload leva a foto pro draft do ✅) e a versão INTERNA anotada vai
     * pra Mesa (jr_pauta_fila) — é lá que moram gate/checklist/fonte.
     *
     * @return ?string messageId da mensagem de TEXTO (alvo do ✅), null = falha
     */
    /**
     * O5 — tell-check no caminho do envio: heurística + cético SEMPRE. Tell
     * grave → regenera 1x informando o tell → re-checa (inclusive grounding)
     * → se persistir, envia assim mesmo com o defeito VISÍVEL (flag). NUNCA
     * segura nem descarta — não é gate.
     *
     * @param  array  $r  passado por referência: a regeneração troca o conteúdo.
     * @return ?string rótulo curto do tell pra linha operacional/gate_motivo, ou null se limpo.
     */
    private function tellCheck(RascunhoCivico $rc, string $atoRef, array &$r): ?string
    {
        $ato = $rc->carregar($atoRef);
        $fonte = (string) ($ato['texto_bruto'] ?? '');
        $tc = app(TellCheck::class);

        $score = $tc->avaliar($r['titulo'], $r['lead'], $r['corpo'], $fonte);
        if (! $score['grave']) {
            return null;
        }

        $motivos = array_map(fn ($t) => $t['tell'], array_filter($score['tells'], fn ($t) => $t['grave']));
        $regen = $rc->gerar($atoRef, $motivos);
        if (! empty($regen)) {
            $r = $regen;
            $score = $tc->avaliar($r['titulo'], $r['lead'], $r['corpo'], $fonte);
        }

        if (! $score['grave']) {
            return null;
        }

        $primeiro = $score['tells'][array_key_first(array_filter($score['tells'], fn ($t) => $t['grave'])) ?? 0]['tell'] ?? 'anti-ia';

        return mb_strimwidth($primeiro, 0, 60, '…');
    }

    private function entregar(ZapRascunhos $zap, string $grupo, RascunhoCivico $rc, array $r, array $c, bool $atualizar = false, ?string $tellFlag = null): ?string
    {
        $foto = null;
        if ($c['url_fonte'] !== '') {
            $foto = app(FotoOficial::class)->buscar($c['url_fonte'], 'Prefeitura de '.$c['municipio']);
        }

        $interno = $this->montarInterno($rc, $r, $c, $foto);

        // fail-closed: sem credencial/grupo o rascunho NÃO circula
        if (! $zap->configurado() || $grupo === '') {
            $this->warn('[SEM CREDENCIAL Z-API/grupo] Rascunho gerado mas NÃO enviado:');
            $this->line(mb_substr($rc->formatarLimpo($r, $foto, false, tellFlag: $tellFlag), 0, 600).'…');

            return null;
        }

        $fotoMsgId = null;
        if ($foto !== null) {
            $fotoMsgId = $zap->imagemArquivo($foto['abs'], $r['titulo'], $grupo);
            if ($fotoMsgId === null) {
                $this->warn("send-image falhou em {$c['ato_ref']} — segue só texto (foto fica pro draft).");
            }
        }

        // texto montado DEPOIS do send-image: crédito de foto nunca fica órfão
        // e a linha operacional avisa quando a foto só vai no draft. O5: flag
        // de tell (se houver) é informação de decisão do editor, nunca gate.
        $texto = $rc->formatarLimpo($r, $foto, $fotoMsgId !== null, tellFlag: $tellFlag);

        $messageId = $zap->texto($texto, $grupo);
        if ($messageId === null && $fotoMsgId === null) {
            $this->error("Z-API recusou {$c['ato_ref']} — nada registrado, tenta no próximo ciclo.");

            return null;
        }
        if ($messageId === null) {
            // foto saiu, texto não: registra COM o id da foto (o ✅ nela casa a
            // entrega) — sem isso o próximo ciclo regeraria foto órfã duplicada.
            $this->warn("texto falhou em {$c['ato_ref']} — entrega registrada pela FOTO ({$fotoMsgId}).");
            $messageId = $fotoMsgId;
        }

        // payload estruturado = insumo do DRAFT WP quando vier o ✅ (Bloco 2/8c)
        $payload = json_encode($r + [
            'municipio' => $c['municipio'],
            'url_fonte' => $c['url_fonte'],
            'foto_path' => $foto['path'] ?? null,
            'foto_credito' => $foto['credito'] ?? null,
            'foto_message_id' => $fotoMsgId,
        ], JSON_UNESCAPED_UNICODE);

        if ($atualizar) {
            DB::table('jr_rascunho_entregas')->where('ato_ref', $c['ato_ref'])->where('tipo', 'auto')->update([
                'message_id' => $messageId,
                'payload' => $payload,
                'updated_at' => Carbon::now(),
            ]);
        } else {
            DB::table('jr_rascunho_entregas')->insertOrIgnore([
                'ato_ref' => $c['ato_ref'],
                'tipo' => 'auto',
                'message_id' => $messageId,
                // O5: flag do tell-check é ANEXADA ao motivo (auditável), nunca
                // substitui/afrouxa a condição real do gate acima.
                'gate_motivo' => $c['gate_motivo'].($tellFlag !== null ? " · ⚠ tell:{$tellFlag}" : ''),
                'payload' => $payload,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        // integra a Mesa: o ato aparece na fila com a versão ANOTADA (interna)
        DB::table('jr_pauta_fila')->updateOrInsert(
            ['ato_ref' => $c['ato_ref']],
            [
                'source' => 'prefeitura',
                'municipio' => $c['municipio'],
                'objeto' => $c['objeto'],
                'score' => $c['score'],
                'url_fonte' => $c['url_fonte'],
                'status' => 'rascunho-gerado',
                'rascunho' => $interno,
                'rascunho_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]
        );

        $this->info("auto-rascunho {$c['ato_ref']} entregue LIMPO (texto {$messageId}"
            .($fotoMsgId ? ", foto {$fotoMsgId}" : ', sem foto').').');

        return $messageId;
    }

    /**
     * BLOCO 8d — prova real: reenvia uma entrega auto existente no formato
     * novo. "ultimo" = a mais recente. Reusa o payload (sem regenerar LLM
     * quando dá), atualiza message_id (o ✅ passa a casar com a msg nova).
     * Não conta no cap diário — é reenvio, não pauta nova.
     */
    private function reenviar(string $ref): int
    {
        $q = DB::table('jr_rascunho_entregas')->where('tipo', 'auto');
        $entrega = $ref === 'ultimo'
            ? $q->orderByDesc('id')->first()
            : $q->where('ato_ref', $ref)->first();
        if (! $entrega) {
            $this->error("Nenhuma entrega auto encontrada pra '{$ref}'.");

            return self::FAILURE;
        }

        $rc = app(RascunhoCivico::class);
        $payload = $entrega->payload ? (array) json_decode($entrega->payload, true) : [];
        if ($this->option('regen')) {
            $payload['titulo'] = null; // força regenerar via LLM (prompt atual)
        }
        if (! empty($payload['titulo'])) {
            $r = [
                'titulo' => (string) $payload['titulo'],
                'lead' => (string) ($payload['lead'] ?? ''),
                'corpo' => (string) ($payload['corpo'] ?? ''),
                'checklist' => (array) ($payload['checklist'] ?? []),
            ];
        } else {
            $r = $rc->gerar($entrega->ato_ref);
            if (empty($r)) {
                $this->error("Entrega sem payload e LLM falhou em {$entrega->ato_ref}.");

                return self::FAILURE;
            }
        }

        $fila = DB::table('jr_pauta_fila')->where('ato_ref', $entrega->ato_ref)->first();
        $c = [
            'ato_ref' => $entrega->ato_ref,
            'municipio' => (string) ($payload['municipio'] ?? $fila->municipio ?? ''),
            'score' => (int) ($fila->score ?? 0),
            'objeto' => (string) ($fila->objeto ?? $r['titulo']),
            'url_fonte' => (string) ($payload['url_fonte'] ?? $fila->url_fonte ?? ''),
            'gate_motivo' => (string) ($entrega->gate_motivo ?? ''),
        ];

        $msgId = $this->entregar(new ZapRascunhos, (string) config('radar_civico.canais.rascunhos'), $rc, $r, $c, atualizar: true);

        return $msgId === null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Candidatos que passam no gate INTEIRO, ordenados por score desc.
     * SÓ jr_prefeitura_noticias — nenhuma outra fonte entra no auto.
     */
    private function candidatos(array $cfg): array
    {
        $corte = Carbon::now()->subHours(max(1, (int) $cfg['frescor_horas']))->toDateString();
        $hoje = Carbon::now()->toDateString();

        $jaEntregue = DB::table('jr_rascunho_entregas')->where('tipo', 'auto')->pluck('ato_ref')->flip();

        $rows = DB::table('jr_prefeitura_noticias')
            ->whereNotNull('score_pauta')
            ->where('score_pauta', '>=', (int) $cfg['score_min'])
            ->whereNotNull('data_pub')
            ->where('data_pub', '>=', $corte)
            ->where('data_pub', '<=', $hoje)
            ->where(fn ($q) => $q->whereNull('data_suspeita')->orWhere('data_suspeita', '!=', 1))
            ->orderByDesc('score_pauta')
            ->get(['id', 'municipio', 'score_pauta', 'data_pub', 'objeto_limpo', 'gancho_curto', 'gancho', 'titulo', 'url_fonte']);

        $out = [];
        foreach ($rows as $a) {
            $atoRef = 'prefeitura:'.$a->id;
            if (isset($jaEntregue[$atoRef])) {
                continue; // dedup
            }
            $muni = (string) ($a->municipio ?? '');
            if (CidadesInteresse::tier($muni) !== 1) {
                continue; // só tier1
            }
            $texto = implode(' ', [(string) $a->titulo, (string) $a->objeto_limpo, (string) ($a->gancho_curto ?: $a->gancho)]);
            [$ok, $categoria] = $this->categoriaBaixoRisco($texto, $cfg);
            if (! $ok) {
                continue;
            }

            $out[] = [
                'ato_ref' => $atoRef,
                'municipio' => $muni,
                'score' => (int) $a->score_pauta,
                'data_pub' => (string) $a->data_pub,
                'objeto' => (string) ($a->objeto_limpo ?: $a->titulo),
                'url_fonte' => (string) ($a->url_fonte ?? ''),
                'gate_motivo' => "🟢 release oficial · score {$a->score_pauta} ≥ {$cfg['score_min']} · {$muni} (tier1) · pub {$a->data_pub} · {$categoria}",
            ];
        }

        return $out;
    }

    /**
     * Categoria de baixo risco: blocklist vermelha VENCE (qualquer match
     * derruba); depois precisa casar >=1 termo da allowlist. Texto normalizado
     * (minúsculas, sem acento) pra casar com os termos do config.
     *
     * @return array{0: bool, 1: string} [passa, termo que classificou]
     */
    private function categoriaBaixoRisco(string $texto, array $cfg): array
    {
        $t = $this->normalizar($texto);
        foreach ((array) $cfg['vermelho'] as $kw) {
            if ($kw !== '' && str_contains($t, $kw)) {
                return [false, "🔴 vermelho: {$kw}"];
            }
        }
        foreach ((array) $cfg['baixo_risco'] as $kw) {
            if ($kw !== '' && str_contains($t, $kw)) {
                return [true, "categoria baixo risco: {$kw}"];
            }
        }

        return [false, 'sem categoria de baixo risco'];
    }

    /** Público pro teste de prova negativa (tinker) sem duplicar a régua. */
    public function avaliarGate(string $texto, array $cfg): array
    {
        return $this->categoriaBaixoRisco($texto, $cfg);
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t === false) {
            return $s;
        }

        // glibc translitera acento como marca separada ("í" → "'i") — remove
        // as marcas pra "matrículas" casar com o termo "matricula" do config.
        return str_replace(["'", '`', '^', '~', '"'], '', $t);
    }

    /**
     * Versão INTERNA anotada — vai pra Mesa (jr_pauta_fila.rascunho), nunca
     * pro grupo: rascunho formatado + gate + checklist + fonte + foto.
     */
    private function montarInterno(RascunhoCivico $rc, array $r, array $c, ?array $foto): string
    {
        $corpo = $rc->formatar($r, [
            'municipio' => $c['municipio'],
            'fonte_nome' => 'Prefeitura (release oficial)',
            'url_fonte' => $c['url_fonte'],
        ]);

        return "🤖 [AUTO] rascunho gerado pelo gate risco×confiança\n"
            ."✅ por que passou: {$c['gate_motivo']}\n\n"
            .$corpo."\n\n"
            .($foto !== null
                ? "📷 foto oficial: {$foto['path']} ({$foto['largura']}×{$foto['altura']}) · crédito: {$foto['credito']}"
                : '📷 sem foto oficial na fonte — buscar/creditar antes de usar')
            ."\nℹ️ release/fonte oficial = VERSÃO — checar contraditório antes de fechar";
    }
}
