<?php

namespace App\Console\Commands;

use App\Services\Jr\OgImage;
use App\Services\Jr\PautaGate;
use App\Services\Jr\PautaReescritor;
use App\Services\Jr\RadarAssunto;
use App\Services\Jr\ZapRascunhos;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Goal foto+whatsapp — ENTREGA ASSÍNCRONA de pauta reescrita no grupo "JR
 * Rascunhos". Roda em BACKGROUND (disparado por nohup pelo JrReescritaController
 * no clique de Montar/Reescrever), pra a tela responder na hora.
 *
 * Fluxo: junta portais do assunto → pega og:image de cada (Parte A, timeout
 * curto, falha não trava) → Opus reescreve unificando (mesmo prompt de hoje, NÃO
 * toca o juiz) → grava em jr_pauta_reescrita → dispara texto + fotos pro grupo
 * via Z-API. Trava solidariedade/vaquinha = fila humana, não reescreve.
 *
 * NÃO publica no WP, NÃO toca o controle, NÃO toca dispatcher/grupos de produção.
 * Manual: 1 chamada = 1 pauta = 1 Opus (assinatura Max).
 */
class JrPautaEntregar extends Command
{
    protected $signature = 'jrpauta:entregar {assunto : assunto_id (a...) ou item solto (i<id>)}';

    protected $description = 'Reescreve unificando os portais + pega fotos (og:image) e entrega a pauta no grupo JR Rascunhos (assíncrono).';

    public function handle(PautaReescritor $reescritor, PautaGate $gate, ZapRascunhos $zap): int
    {
        $assuntoId = (string) $this->argument('assunto');
        $membros = RadarAssunto::membros($assuntoId);
        if ($membros->isEmpty()) {
            $this->marcarErro($assuntoId, 'assunto não encontrado');

            return self::FAILURE;
        }

        $comTexto = $membros->filter(fn ($m) => trim((string) $m->markdown) !== '')->values();
        if ($comTexto->isEmpty()) {
            $this->marcarErro($assuntoId, 'nenhum portal com texto extraído');
            $zap->texto("⚠️ Não consegui montar a pauta: nenhum portal desse assunto tem texto extraído.");

            return self::FAILURE;
        }

        // ── Parte A: og:image por portal (falha não trava a pauta) ──
        $tOg = (int) config('jrlink.rascunhos.og_timeout', 7);
        $maxFotos = (int) config('jrlink.rascunhos.max_fotos', 6);
        $fotos = [];
        foreach ($comTexto as $m) {
            if (count($fotos) >= $maxFotos) {
                break;
            }
            $og = OgImage::de((string) $m->url, $tOg);
            $fotos[] = ['host' => (string) $m->host, 'url' => (string) $m->url, 'og_image' => $og];
            $this->line(sprintf('og:image %s -> %s', $m->host, $og ? 'ok' : 'SEM FOTO'));
        }

        // ── Trava dura solidariedade/vaquinha (crime PASSA, é decisão manual) ──
        $textotodo = $comTexto->map(fn ($m) => $m->titulo . "\n" . $m->markdown)->implode("\n\n");
        [$gateRes, $gateMotivo] = $gate->avaliar($textotodo);
        if ($gateRes === PautaGate::FILA_SOLIDARIEDADE) {
            $this->gravar($assuntoId, $membros, $comTexto, $fotos, null, $gateRes);
            $zap->texto("🚫 *Bloqueado — solidariedade/vaquinha/Pix*\n" . $gateMotivo
                . "\nVai pra fila humana, não reescrevo no automático.");
            $this->marcarEntrega($assuntoId, 'enviado', null, []);

            return self::SUCCESS;
        }

        // ── Opus reescreve unificando (mesmo prompt; NÃO toca o juiz) ──
        $cidade = $comTexto->pluck('cidade_llm')->filter()->first();
        $portais = $comTexto->map(fn ($m) => [
            'host' => (string) $m->host, 'titulo' => (string) $m->titulo, 'texto' => (string) $m->markdown,
        ])->all();

        try {
            $r = $reescritor->reescreverUnificado($portais, $cidade);
        } catch (\Throwable $e) {
            $this->marcarErro($assuntoId, 'reescrita falhou: ' . $e->getMessage());
            $zap->texto("⚠️ Falha ao reescrever a pauta (" . mb_substr($e->getMessage(), 0, 120) . "). Tenta de novo.");

            return self::FAILURE;
        }

        $this->gravar($assuntoId, $membros, $comTexto, $fotos, $r, 'ok');

        // ── Monta e dispara a mensagem + fotos ──
        $ids = [];
        $msg = $this->montarMensagem($r, $fotos);
        if ($id = $zap->texto($msg)) {
            $ids[] = $id;
        }
        foreach ($fotos as $f) {
            if (! empty($f['og_image'])) {
                if ($id = $zap->imagem($f['og_image'], '📷 ' . $f['host'] . ' — SÓ APURAÇÃO, não usar no post (foto de portal, direito autoral)')) {
                    $ids[] = $id;
                }
            }
        }

        $this->marcarEntrega($assuntoId, empty($ids) ? 'erro' : 'enviado', empty($ids) ? 'Z-API não retornou messageId' : null, $ids);
        $this->info(sprintf('Entregue: %d mensagens (1 texto + %d fotos) no grupo Rascunhos.', count($ids), max(0, count($ids) - 1)));

        return self::SUCCESS;
    }

    /** Mensagem WhatsApp: título + linha fina + matéria + lacunas + tags + fontes. */
    private function montarMensagem(array $r, array $fotos): string
    {
        $L = [];
        $L[] = '📝 *RASCUNHO JR — ' . ($r['titulo_principal'] ?: 'pauta') . '*';
        if (! empty($r['cidade']) || ! empty($r['editoria'])) {
            $L[] = '_' . trim(implode(' · ', array_filter([$r['editoria'] ?? null, $r['cidade'] ?? null]))) . '_';
        }
        if (! empty($r['linha_fina'])) {
            $L[] = '';
            $L[] = $r['linha_fina'];
        }
        $L[] = '';
        $L[] = $r['materia'];

        if (! empty($r['titulos']) && count($r['titulos']) > 1) {
            $L[] = '';
            $L[] = '*Outros títulos:*';
            foreach (array_slice($r['titulos'], 1, 6) as $t) {
                $L[] = '• ' . $t;
            }
        }
        if (! empty($r['lacunas'])) {
            $L[] = '';
            $L[] = '⚠️ *Confirmar antes de publicar:*';
            foreach ($r['lacunas'] as $lac) {
                $L[] = '• ' . $lac;
            }
        }
        if (! empty($r['tags'])) {
            $L[] = '';
            $L[] = '🏷️ ' . implode(' · ', $r['tags']);
        }
        // Fotos / portais sem foto
        $semFoto = array_values(array_filter($fotos, fn ($f) => empty($f['og_image'])));
        $L[] = '';
        $comFoto = count($fotos) - count($semFoto);
        $L[] = '🖼️ ' . $comFoto . ' foto(s) abaixo, por portal.';
        if ($semFoto) {
            $L[] = 'Sem foto (pegar na fonte): ' . implode(', ', array_map(fn ($f) => $f['host'], $semFoto));
        }

        return implode("\n", $L);
    }

    /** Upsert do resultado + fotos em jr_pauta_reescrita. */
    private function gravar(string $assuntoId, $membros, $comTexto, array $fotos, ?array $r, string $gate): void
    {
        $base = [
            'cluster_ids' => json_encode($membros->pluck('cluster_id')->filter()->unique()->values()),
            'fontes' => json_encode($comTexto->map(fn ($m) => ['host' => $m->host, 'url' => $m->url])->values()),
            'n_portais' => $comTexto->count(),
            'fotos' => json_encode($fotos, JSON_UNESCAPED_UNICODE),
            'gate' => $gate,
            'gerado_em' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ];
        if ($r) {
            $base += [
                'cidade' => $r['cidade'], 'editoria' => $r['editoria'],
                'titulos' => json_encode($r['titulos'], JSON_UNESCAPED_UNICODE),
                'titulo_principal' => $r['titulo_principal'],
                'linha_fina' => $r['linha_fina'],
                'materia' => $r['materia'],
                'tags' => json_encode($r['tags'], JSON_UNESCAPED_UNICODE),
                'lacunas' => json_encode($r['lacunas'], JSON_UNESCAPED_UNICODE),
                'modelo' => $r['modelo'],
                'custo_usd' => (float) DB::table('jr_juiz_log')->where('operation', 'reescrita_unificada')
                    ->where('created_at', '>=', Carbon::now()->subMinutes(5))->sum('custo_usd'),
            ];
        } else {
            $base += [
                'titulos' => json_encode([]), 'materia' => '', 'tags' => json_encode([]),
                'lacunas' => json_encode([$gate === PautaGate::FILA_SOLIDARIEDADE ? 'Solidariedade/vaquinha — fila humana.' : '']),
            ];
        }
        DB::table('jr_pauta_reescrita')->updateOrInsert(['assunto_id' => $assuntoId], $base);
    }

    private function marcarEntrega(string $assuntoId, string $status, ?string $erro, array $ids): void
    {
        DB::table('jr_pauta_reescrita')->where('assunto_id', $assuntoId)->update([
            'entrega_status' => $status,
            'entrega_erro' => $erro,
            'zap_ids' => json_encode($ids),
            'entregue_em' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function marcarErro(string $assuntoId, string $erro): void
    {
        $this->error($erro);
        // só UPDATE: a linha 'pendente' já foi semeada pelo controller no clique.
        DB::table('jr_pauta_reescrita')->where('assunto_id', $assuntoId)->update([
            'entrega_status' => 'erro', 'entrega_erro' => $erro, 'updated_at' => Carbon::now(),
        ]);
    }
}
