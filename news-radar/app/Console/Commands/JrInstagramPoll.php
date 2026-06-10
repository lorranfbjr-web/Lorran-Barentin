<?php

namespace App\Console\Commands;

use App\Services\Jr\PautaClassifier;
use App\Services\Jr\RadarNotificador;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CANAL INSTAGRAM — perfis só-IG na MESMA esteira do radar: o poll roda o ator
 * Apify na lista de perfis e cria itens em jr_link_extracao pelo MESMO caminho
 * de entrada do canal WhatsApp/feed (PautaClassifier: gate + régua
 * concorrente_feed; categoria forçada 'concorrente' = radar, nunca reescreve).
 * Daí em diante é a esteira normal: cluster → juiz v3 → aba Radar → notificação.
 *
 * Dedup PERMANENTE por shortcode (url canônica do post → url_hash unique;
 * re-poll não duplica). likesCount do ator vem oculto e é ignorado.
 * Kill switch: JRLINK_IG_ENABLED=false ou profiles vazio.
 */
class JrInstagramPoll extends Command
{
    protected $signature = 'jrlink:instagram-poll {--dry : Coleta e mostra sem gravar}';

    protected $description = 'Poll dos perfis só-Instagram via Apify -> itens origem=instagram na régua do radar.';

    private const CACHE_FALHAS = 'jrlink_ig_poll_falhas';

    private const CACHE_ALERTA = 'jrlink_ig_poll_alerta_em';

    public function handle(): int
    {
        $cfg = config('jrlink.instagram', []);
        $profiles = (array) ($cfg['profiles'] ?? []);
        if (! ($cfg['enabled'] ?? false) || ! $profiles) {
            $this->line('Canal Instagram desligado (JRLINK_IG_ENABLED/profiles).');

            return self::SUCCESS;
        }

        try {
            [$posts, $runId, $custo] = $this->coletar($cfg, $profiles);
        } catch (\Throwable $e) {
            $this->registrarFalha($cfg, $e->getMessage());
            $this->error('Poll falhou: ' . $e->getMessage());

            return self::FAILURE;
        }
        Cache::forget(self::CACHE_FALHAS);

        $novos = $this->ingerir($posts, (int) ($cfg['min_legenda'] ?? 25), (bool) $this->option('dry'));

        Log::info(sprintf('[IgPoll] run=%s perfis=%d posts=%d novos=%d pulados_sem_legenda=%d custo_usd=%s',
            $runId, count($profiles), count($posts), $novos['novos'], $novos['sem_legenda'],
            $custo !== null ? number_format((float) $custo, 4) : '?'));
        $this->info(sprintf('Poll OK: %d posts coletados · %d novos na régua · %d sem legenda útil · custo US$ %s',
            count($posts), $novos['novos'], $novos['sem_legenda'],
            $custo !== null ? number_format((float) $custo, 4) : '?'));

        return self::SUCCESS;
    }

    /** @return array{0:array,1:string,2:?float} [posts, runId, custoUsd] */
    private function coletar(array $cfg, array $profiles): array
    {
        $token = (string) env('APIFY_TOKEN');
        if ($token === '') {
            throw new \RuntimeException('APIFY_TOKEN ausente');
        }

        $run = Http::timeout(30)->post(
            'https://api.apify.com/v2/acts/' . ($cfg['actor_id'] ?? 'apify~instagram-post-scraper') . '/runs?token=' . $token,
            ['username' => array_values($profiles), 'resultsLimit' => (int) ($cfg['max_posts_por_perfil'] ?? 3)],
        );
        if (! $run->successful()) {
            throw new \RuntimeException('start HTTP ' . $run->status());
        }
        $runId = (string) $run->json('data.id');
        $datasetId = (string) $run->json('data.defaultDatasetId');

        $status = 'RUNNING';
        $custo = null;
        for ($i = 0; $i < 60 && in_array($status, ['RUNNING', 'READY'], true); $i++) {
            sleep(5);
            $info = Http::timeout(20)->get("https://api.apify.com/v2/actor-runs/{$runId}?token={$token}");
            $status = (string) $info->json('data.status');
            $custo = $info->json('data.usageTotalUsd');
        }
        if ($status !== 'SUCCEEDED') {
            throw new \RuntimeException("run {$runId} terminou {$status}");
        }

        $posts = Http::timeout(60)->get(
            "https://api.apify.com/v2/datasets/{$datasetId}/items?token={$token}&clean=true&format=json")->json() ?: [];

        return [$posts, $runId, $custo];
    }

    /** Cria itens na régua pelo caminho padrão (gate + régua concorrente_feed). */
    private function ingerir(array $posts, int $minLegenda, bool $dry): array
    {
        $clf = new PautaClassifier();
        $rows = [];
        $semLegenda = 0;

        foreach ($posts as $p) {
            $shortcode = $p['shortCode'] ?? null;
            if (! $shortcode) {
                continue;
            }
            $legenda = trim((string) ($p['caption'] ?? ''));
            $perfil = '@' . ($p['ownerUsername'] ?? 'desconhecido');
            if (mb_strlen($legenda) < $minLegenda) {
                $semLegenda++;
                Log::info("[IgPoll] sem legenda útil, pulado: {$perfil} {$shortcode}");
                continue;
            }

            $url = 'https://www.instagram.com/p/' . $shortcode . '/';
            $titulo = mb_substr(trim(preg_split('/\r?\n/', $legenda)[0] ?? ''), 0, 120);

            // MESMO caminho do canal whatsapp/feed: gate de corpo + régua
            // quente/frio. Perfil de notícia local = concorrente (radar).
            $categoria = 'concorrente';
            $status = $clf->gateStatus($categoria, $titulo, $legenda);
            [$eixo, $temperatura, $score] = $clf->temperatura($categoria, $titulo, $legenda, [], $url, 'concorrente_feed');

            $rows[] = [
                'url' => $url,
                'url_hash' => $clf->urlHashNorm($url),   // shortcode canônico = dedup permanente
                'url_norm' => $clf->normalizeUrl($url),
                'host' => 'instagram.com',
                'fonte_tipo' => $perfil,
                'origem' => 'instagram',
                'categoria' => $categoria,
                'eixo' => $eixo,
                'temperatura' => $temperatura,
                'score' => $score,
                'metodo' => 'apify',
                'titulo' => $titulo,
                'data_pub' => isset($p['timestamp']) ? Carbon::parse($p['timestamp'])->format('Y-m-d H:i:s') : null,
                'autor' => $perfil,
                'char_len' => mb_strlen($legenda),
                'status' => $status,
                'markdown' => $legenda,
                'created_at' => Carbon::now(),
            ];
        }

        if ($dry) {
            foreach ($rows as $r) {
                $this->line(sprintf('  [%s/%d] %s %s', $r['temperatura'] ?? '-', $r['score'], $r['fonte_tipo'],
                    mb_strimwidth($r['titulo'], 0, 70)));
            }

            return ['novos' => 0, 'sem_legenda' => $semLegenda];
        }

        $antes = (int) DB::table('jr_link_extracao')->where('origem', 'instagram')->count();
        foreach (array_chunk($rows, 100) as $chunk) {
            // upsert NÃO atualiza colunas do juiz/cluster — re-poll não re-zera veredito.
            DB::table('jr_link_extracao')->upsert($chunk, ['url_hash'], [
                'titulo', 'data_pub', 'char_len', 'status', 'markdown',
            ]);
        }
        $novos = (int) DB::table('jr_link_extracao')->where('origem', 'instagram')->count() - $antes;

        return ['novos' => $novos, 'sem_legenda' => $semLegenda];
    }

    /** 3 falhas seguidas -> 1 alerta no Raspador (com supressão). */
    private function registrarFalha(array $cfg, string $erro): void
    {
        $falhas = (int) Cache::increment(self::CACHE_FALHAS);
        Log::warning("[IgPoll] falha #{$falhas}: {$erro}");

        if ($falhas < (int) ($cfg['falhas_para_alerta'] ?? 3)) {
            return;
        }
        if (Cache::get(self::CACHE_ALERTA)) {
            return; // supressão: já alertou na janela
        }
        $id = (new RadarNotificador())->avisar(sprintf(
            "⚠️ Radar JR: o poll do Instagram falhou %d vezes seguidas.\nÚltimo erro: %s\nVou seguir tentando a cada 15min; verifique o APIFY_TOKEN/ator se persistir.",
            $falhas, mb_substr($erro, 0, 150)));
        if ($id !== null) {
            Cache::put(self::CACHE_ALERTA, now()->toIso8601String(),
                now()->addMinutes((int) ($cfg['supressao_alerta_min'] ?? 360)));
            Log::warning("[IgPoll] alerta enviado ao Raspador: {$id}");
        }
    }
}
