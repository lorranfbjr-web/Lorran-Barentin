<?php

namespace App\Console\Commands;

use App\Services\Jr\CidadesInteresse;
use App\Services\Jr\JanelaSilencio;
use App\Services\Jr\RascunhoCivico;
use App\Services\Jr\ZapRascunhos;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BLOCO 1 (03/07) — FLYWHEEL SALTO 1: auto-rascunho SELETIVO.
 *
 * Pega release 🟢 de prefeitura (serviço/1ª-mão) que passou no gate
 * risco×confiança e entrega um rascunho padrão JR pronto no grupo RASCUNHOS,
 * prefixado [AUTO], SEM FOTO (campo de imagem fica vazio de propósito —
 * Trava #0: buscar/creditar foto é decisão humana).
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
 *   --dry : avalia o gate e imprime o que faria, sem LLM, sem envio
 */
class JrCivicoAutoRascunho extends Command
{
    protected $signature = 'jrcivico:auto-rascunho {--dry : avalia o gate e imprime, não gera nem envia} {--max= : teto extra deste run (nunca passa do cap diário)}';

    protected $description = 'Auto-rascunho seletivo: release 🟢 de prefeitura que passa no gate risco×confiança vira rascunho [AUTO] no grupo RASCUNHOS. Cap diário, dedup, silêncio, fail-closed.';

    public function handle(): int
    {
        $cfg = config('radar_civico.auto_rascunho');
        $dry = (bool) $this->option('dry');

        if (! $dry && JanelaSilencio::ativa(config('radar_civico.alertas'))) {
            $this->info('Silêncio: auto-rascunho espera o dia acordar.');
            return self::SUCCESS;
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

        $zap = new ZapRascunhos();
        $grupo = (string) config('radar_civico.canais.rascunhos');
        if (($max = (int) $this->option('max')) > 0) {
            $restante = min($restante, $max);
        }
        $enviaveis = array_slice($candidatos, 0, $restante);
        $this->info(count($candidatos) . ' no gate, ' . count($enviaveis) . " dentro do cap (restavam {$restante}).");

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
            $texto = $this->montar($rc, $r, $c);

            // fail-closed: sem credencial/grupo o rascunho NÃO circula
            if (! $zap->configurado() || $grupo === '') {
                $this->warn('[SEM CREDENCIAL Z-API/grupo] Rascunho gerado mas NÃO enviado:');
                $this->line(mb_substr($texto, 0, 600) . '…');
                continue;
            }

            $messageId = $zap->texto($texto, $grupo);
            if ($messageId === null) {
                $this->error("Z-API recusou {$c['ato_ref']} — nada registrado, tenta no próximo ciclo.");
                continue;
            }

            DB::table('jr_rascunho_entregas')->insertOrIgnore([
                'ato_ref' => $c['ato_ref'],
                'tipo' => 'auto',
                'message_id' => $messageId,
                'gate_motivo' => $c['gate_motivo'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // integra a Mesa: o ato aparece na fila já com rascunho pronto
            DB::table('jr_pauta_fila')->updateOrInsert(
                ['ato_ref' => $c['ato_ref']],
                [
                    'source' => 'prefeitura',
                    'municipio' => $c['municipio'],
                    'objeto' => $c['objeto'],
                    'score' => $c['score'],
                    'url_fonte' => $c['url_fonte'],
                    'status' => 'rascunho-gerado',
                    'rascunho' => $texto,
                    'rascunho_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                ]
            );

            $this->info("[AUTO] {$c['ato_ref']} entregue (messageId {$messageId}).");
        }

        return self::SUCCESS;
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
            $atoRef = 'prefeitura:' . $a->id;
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
     * Mensagem [AUTO] no grupo: prefixo, rascunho formatado padrão JR,
     * 1 linha do gate, SEM foto (linha explícita) e disclaimer de versão.
     */
    private function montar(RascunhoCivico $rc, array $r, array $c): string
    {
        $corpo = $rc->formatar($r, [
            'municipio' => $c['municipio'],
            'fonte_nome' => 'Prefeitura (release oficial)',
        ]);

        return "🤖 *[AUTO]* rascunho gerado pelo gate risco×confiança\n"
            . "✅ _por que passou:_ {$c['gate_motivo']}\n\n"
            . $corpo . "\n\n"
            . "📷 foto: buscar/creditar antes de usar (auto NUNCA anexa imagem)\n"
            . "ℹ️ release/fonte oficial = VERSÃO — checar contraditório antes de fechar\n"
            . ($c['url_fonte'] !== '' ? "🔗 {$c['url_fonte']}" : '');
    }
}
