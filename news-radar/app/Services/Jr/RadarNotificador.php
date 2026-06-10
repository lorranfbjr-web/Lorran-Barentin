<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notificação do Radar JR — digest de pautas quentes NOVAS pro grupo WhatsApp
 * "Raspador", via Z-API DIRETO (HTTP daqui do Laravel). Caminho 100% próprio:
 * não encosta no workflow do disparador (g6ApLgldIyHKLwdq) nem no dedup-svc.
 *
 * Contrato:
 *  - 1 mensagem por ciclo do juiz, SÓ com quentes nunca notificados; sem novos
 *    = silêncio total.
 *  - Dedup permanente via jr_link_extracao.notificado_em (cluster inteiro é
 *    marcado junto — a mesma história nunca volta).
 *  - Cap de itens por mensagem; excedente vira "+N no relatório" (e também é
 *    marcado — digest, não fila).
 *  - Janela de silêncio (default 23h-06h): acumula pro 1º ciclo da manhã.
 *  - Kill switch: qualquer env JRLINK_ALERT_ZAPI_x / JRLINK_ALERT_GROUP vazio
 *    = desligado (loga e sai).
 */
class RadarNotificador
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? config('jrlink.notificacao', []);
    }

    public function ligado(): bool
    {
        foreach (['instance', 'token', 'client_token', 'grupo'] as $k) {
            if (trim((string) ($this->cfg[$k] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    public function emJanelaDeSilencio(?Carbon $agora = null): bool
    {
        $agora = $agora ?? Carbon::now();
        $ini = (int) ($this->cfg['silencio_inicio'] ?? 23);
        $fim = (int) ($this->cfg['silencio_fim'] ?? 6);
        $h = (int) $agora->format('G');

        return $ini > $fim ? ($h >= $ini || $h < $fim) : ($h >= $ini && $h < $fim);
    }

    /**
     * Seleciona, envia (se houver) e marca. Retorna resumo pra log do comando.
     *
     * @return array{status:string, novos:int, enviados:int, message_id:?string}
     */
    public function notificarNovos(): array
    {
        if (! $this->ligado()) {
            return ['status' => 'desligado', 'novos' => 0, 'enviados' => 0, 'message_id' => null];
        }
        if ($this->emJanelaDeSilencio()) {
            return ['status' => 'silencio', 'novos' => 0, 'enviados' => 0, 'message_id' => null];
        }

        $corte = (int) config('jrlink.juiz.corte_quente_final', 60);
        $novos = DB::table('jr_link_extracao')
            ->where('temperatura_juiz', 'quente')
            ->where('score_editorial', '>=', $corte)
            ->where('duplicada', false)
            ->where(function ($q) {
                $q->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->whereNull('notificado_em')
            ->orderByDesc('score_editorial')->orderByDesc('id')
            ->get();

        if ($novos->isEmpty()) {
            return ['status' => 'sem_novos', 'novos' => 0, 'enviados' => 0, 'message_id' => null];
        }

        $cap = (int) ($this->cfg['max_itens'] ?? 10);
        $mensagem = $this->montar($novos, $cap);

        $messageId = $this->enviar($mensagem);
        if ($messageId === null) {
            // Falha de envio: NÃO marca — tenta de novo no próximo ciclo.
            return ['status' => 'falha_envio', 'novos' => $novos->count(), 'enviados' => 0, 'message_id' => null];
        }

        $this->marcarNotificados($novos);
        Log::info(sprintf('[RadarNotificador] digest enviado: %d novos (%d na mensagem) messageId=%s',
            $novos->count(), min($cap, $novos->count()), $messageId));

        return ['status' => 'enviado', 'novos' => $novos->count(),
            'enviados' => min($cap, $novos->count()), 'message_id' => $messageId];
    }

    /** Estreia anti-flood: marca TODO o estoque atual como já-notificado. */
    public function seedEstoque(): int
    {
        return DB::table('jr_link_extracao')
            ->where('temperatura_juiz', 'quente')
            ->whereNull('notificado_em')
            ->update(['notificado_em' => Carbon::now()]);
    }

    // ───────────────────────── interno ─────────────────────────

    private function montar($novos, int $cap): string
    {
        $L = [];
        $L[] = sprintf('🔥 *Radar JR — %d pauta%s nova%s*', $novos->count(),
            $novos->count() > 1 ? 's' : '', $novos->count() > 1 ? 's' : '');
        $L[] = '';

        foreach ($novos->take($cap) as $i => $r) {
            $eixo = $r->eixo === 'primaria' ? 'PRIMÁRIA' : 'RADAR';
            $meta = array_filter([
                $r->cidade_llm,
                $r->tipo_gancho && $r->tipo_gancho !== 'nenhum' ? str_replace('_', ' ', $r->tipo_gancho) : null,
            ]);
            $L[] = sprintf('%d. [%s %d] %s', $i + 1, $eixo, (int) $r->score_editorial,
                $meta ? implode(' · ', $meta) : '—');
            $L[] = trim((string) $r->titulo);
            $L[] = (string) $r->url;
            $L[] = '';
        }

        if ($novos->count() > $cap) {
            $L[] = sprintf('+%d no relatório', $novos->count() - $cap);
            $L[] = '';
        }

        $L[] = 'Relatório: ' . ($this->cfg['relatorio_url'] ?? 'https://jornaldetijucas.com.br/_tmp_jrlink/extract.html');

        return implode("\n", $L);
    }

    /** POST send-text na Z-API. Retorna messageId ou null. */
    private function enviar(string $mensagem): ?string
    {
        $url = sprintf('https://api.z-api.io/instances/%s/token/%s/send-text',
            $this->cfg['instance'], $this->cfg['token']);
        try {
            $r = Http::withHeaders(['Client-Token' => $this->cfg['client_token']])
                ->timeout(20)
                ->post($url, ['phone' => $this->cfg['grupo'], 'message' => $mensagem]);
            if ($r->successful() && ($id = $r->json('messageId'))) {
                return (string) $id;
            }
            Log::warning('[RadarNotificador] envio falhou: HTTP ' . $r->status() . ' ' . mb_substr($r->body(), 0, 200));
        } catch (\Throwable $e) {
            Log::warning('[RadarNotificador] envio falhou: ' . $e->getMessage());
        }

        return null;
    }

    /** Marca os selecionados E os membros dos clusters deles (história inteira). */
    private function marcarNotificados($novos): void
    {
        $agora = Carbon::now();
        DB::table('jr_link_extracao')->whereIn('id', $novos->pluck('id'))->update(['notificado_em' => $agora]);
        $clusters = $novos->pluck('cluster_id')->filter()->unique();
        if ($clusters->isNotEmpty()) {
            DB::table('jr_link_extracao')->whereIn('cluster_id', $clusters)
                ->whereNull('notificado_em')->update(['notificado_em' => $agora]);
        }
    }
}
