<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        // Janela é em hora LOCAL do editor — o app roda em UTC (silenciaria
        // 20h-03h de Brasília se usasse now() puro).
        $agora = $agora ?? Carbon::now($this->cfg['timezone'] ?? 'America/Sao_Paulo');
        $ini = (int) ($this->cfg['silencio_inicio'] ?? 23);
        $fim = (int) ($this->cfg['silencio_fim'] ?? 6);
        $h = (int) $agora->format('G');

        return $ini > $fim ? ($h >= $ini || $h < $fim) : ($h >= $ini && $h < $fim);
    }

    /**
     * Plano do digest SEM efeito colateral (não envia, não marca): separa os
     * candidatos nunca-notificados em enviáveis × bloqueados por idade da
     * publicação original (> notificacao.max_idade_horas — catch-up de matéria
     * velha não vira WhatsApp). Já-publicado no site NUNCA entra (v4.1).
     *
     * @return array{enviaveis: Collection, bloqueados_idade: Collection, bloqueados_publicado: Collection, match_pub: array}
     */
    public function planejar(): array
    {
        $corte = (int) config('jrlink.juiz.corte_quente_final', 60);
        $candidatos = DB::table('jr_link_extracao')
            ->where('temperatura_juiz', 'quente')
            ->where('score_editorial', '>=', $corte)
            ->where('duplicada', false)
            ->where(function ($q) {
                $q->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->whereNull('notificado_em')
            ->whereNull('ja_publicado_em')
            ->orderByDesc('score_editorial')->orderByDesc('id')
            ->get();

        $maxIdade = (int) ($this->cfg['max_idade_horas'] ?? 12);
        [$enviaveis, $velhos] = $candidatos->partition(function ($r) use ($maxIdade) {
            $idade = $this->idadeHoras($r->data_pub ?: $r->created_at);

            return $idade === null || $idade <= $maxIdade;
        });

        // CHECAGEM SÍNCRONA NO ATO DE NOTIFICAR (corrige a corrida do publicados-
        // sync de 30min): confronta cada enviável contra jr_publicado AGORA — não
        // confia que o sync já marcou. Match → sai do digest (e é marcado no DB
        // por notificarNovos).
        $matchPub = $this->confirmarPublicados($enviaveis->values());
        [$publicados, $enviaveis] = $enviaveis->partition(fn ($r) => isset($matchPub[$r->id]));

        return [
            'enviaveis' => $enviaveis->values(),
            'bloqueados_idade' => $velhos->values(),
            'bloqueados_publicado' => $publicados->values(),
            'match_pub' => $matchPub,
        ];
    }

    /**
     * Match síncrono dos eventos prestes a notificar contra jr_publicado (janela
     * de DIAS): pré-filtro largo + decisão do Opus (PublicadoMatcher), o mesmo
     * motor do publicados-sync. Retorna [evento_id => post casado].
     *
     * @return array<int,object>
     */
    private function confirmarPublicados(Collection $eventos): array
    {
        if ($eventos->isEmpty()) {
            return [];
        }
        $dias = (int) config('jrlink.publicados.janela_match_dias', 30);
        $cap = (int) config('jrlink.publicados.llm_cap_notificar', 40);
        $alvos = DB::table('jr_publicado')
            ->where('publicado_em', '>=', Carbon::now()->subDays($dias)->toDateTimeString())
            ->get(['slug', 'titulo', 'publicado_em'])
            ->map(fn ($p) => (object) ['chave' => $p->slug, 'titulo' => $p->titulo,
                'quando' => $p->publicado_em, 'extra' => $p->slug]);

        return (new PublicadoMatcher())->casar($eventos, $alvos, new JuizLlm(), $cap, 'site');
    }

    /** Idade em horas de um datetime string heterogêneo; null se imprestável. */
    private function idadeHoras(?string $dt): ?float
    {
        if ($dt === null || trim($dt) === '') {
            return null;
        }
        try {
            return Carbon::now()->diffInHours(Carbon::parse($dt), true);
        } catch (\Throwable) {
            return null;
        }
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

        $plano = $this->planejar();
        $novos = $plano['enviaveis'];

        // JÁ PUBLICADO detectado NO ATO (a corrida que o sync de 30min perdia):
        // marca ja_publicado_em no cluster inteiro e NÃO notifica.
        if ($plano['bloqueados_publicado']->isNotEmpty()) {
            $this->marcarPublicados($plano['bloqueados_publicado'], $plano['match_pub']);
            Log::info(sprintf('[RadarNotificador] %d evento(s) já-publicado(s) barrado(s) no ATO de notificar (match síncrono)',
                $plano['bloqueados_publicado']->count()));
        }

        // Velho demais = tratado (marca sem enviar) — não acumula no candidato
        // de todo ciclo nem nunca vira WhatsApp. Mesma semântica de digest.
        if ($plano['bloqueados_idade']->isNotEmpty()) {
            $this->marcarNotificados($plano['bloqueados_idade']);
            Log::info(sprintf('[RadarNotificador] %d quente(s) bloqueado(s) por idade > %dh (catch-up, não notifica)',
                $plano['bloqueados_idade']->count(), (int) ($this->cfg['max_idade_horas'] ?? 12)));
        }

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

    /**
     * Aviso operacional avulso pro grupo (ex.: poll do Instagram falhando).
     * Respeita o kill switch e a janela de silêncio. Retorna messageId ou null.
     */
    public function avisar(string $mensagem): ?string
    {
        if (! $this->ligado() || $this->emJanelaDeSilencio()) {
            return null;
        }

        return $this->enviar($mensagem);
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

    /**
     * Monta o digest AGRUPADO POR EVENTO (1 bloco por cluster, como o painel
     * Radar): evento com N≥2 portais vira um bloco só com o título do líder +
     * "🔥 N portais cobrindo: link1 · link2 · …". Evento de 1 portal segue
     * simples (título + link). As coberturas saem em 1 query (sem N+1).
     */
    public function montar($novos, int $cap): string
    {
        $coberturas = $this->coberturasPorEvento($novos);
        $maxLinks = (int) ($this->cfg['max_links_por_evento'] ?? 6);

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

            $fontes = $coberturas[$r->cluster_id] ?? null;
            if ($fontes && $fontes->count() >= 2) {
                $links = $fontes->take($maxLinks)->map(fn ($f) => (string) $f['url'])->implode(' · ');
                $extra = $fontes->count() > $maxLinks ? sprintf(' (+%d)', $fontes->count() - $maxLinks) : '';
                $L[] = sprintf('🔥 %d portais cobrindo:%s', $fontes->count(), $extra);
                $L[] = $links;
            } else {
                $L[] = (string) $r->url;
            }
            $L[] = '';
        }

        if ($novos->count() > $cap) {
            $L[] = sprintf('+%d no relatório', $novos->count() - $cap);
            $L[] = '';
        }

        // Vitrine /radar ao vivo (lê o banco na hora, agrupado por assunto) — não
        // o snapshot estático antigo _tmp_jrlink/extract.html.
        $L[] = 'Relatório: ' . ($this->cfg['relatorio_url'] ?? 'https://jornaldetijucas.com.br/radar');

        return implode("\n", $L);
    }

    /**
     * Fontes distintas (1 melhor URL por fonte) de cada cluster dos eventos —
     * em 1 query só (sem N+1). Retorna [cluster_id => Collection<['nome','url']>].
     */
    private function coberturasPorEvento($novos): array
    {
        $clusterIds = $novos->pluck('cluster_id')->filter()->unique()->values();
        if ($clusterIds->isEmpty()) {
            return [];
        }

        return DB::table('jr_link_extracao')
            ->whereIn('cluster_id', $clusterIds)->where('duplicada', false)
            ->get(['cluster_id', 'url', 'host', 'fonte_tipo', 'score'])
            ->groupBy('cluster_id')
            ->map(function ($membros) {
                return $membros
                    ->groupBy(fn ($m) => $m->fonte_tipo ?: $m->host ?: '?')
                    ->map(fn ($g, $nome) => [
                        'nome' => $nome,
                        'url' => $g->sortByDesc('score')->first()->url,
                    ])->values();
            })->all();
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

    /**
     * Marca como já-publicado (cluster inteiro) os eventos casados no ATO de
     * notificar — mesmo efeito do publicados-sync, mas síncrono. Some do Radar
     * e nunca mais entra no digest.
     *
     * @param  array<int,object>  $matchPub  evento_id => post casado
     */
    private function marcarPublicados(Collection $eventos, array $matchPub): void
    {
        foreach ($eventos as $r) {
            $alvo = $matchPub[$r->id] ?? null;
            if (! $alvo) {
                continue;
            }
            $q = $r->cluster_id
                ? DB::table('jr_link_extracao')->where('cluster_id', $r->cluster_id)
                : DB::table('jr_link_extracao')->where('id', $r->id);
            $q->whereNull('ja_publicado_em')->update([
                'ja_publicado_em' => $alvo->quando,
                'ja_publicado_slug' => $alvo->extra,
            ]);
            Log::info(sprintf('[RadarNotificador] barrado já-publicado: "%s" ← %s',
                mb_strimwidth((string) $r->titulo, 0, 60), $alvo->extra));
        }
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
