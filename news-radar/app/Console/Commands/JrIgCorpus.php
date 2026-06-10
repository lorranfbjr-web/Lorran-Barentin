<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Puxa o corpus de posts do @jornalrazao via Apify (instagram-post-scraper) e
 * grava em jr_ig_corpus (upsert por shortcode — re-rodável, ex.: mensal).
 * likesCount do ator vem OCULTO (0/1 falso) e é ignorado de propósito.
 */
class JrIgCorpus extends Command
{
    protected $signature = 'jrlink:ig-corpus {--limit=400 : Posts recentes a puxar} {--username=jornalrazao}';

    protected $description = 'Corpus do Instagram via Apify -> jr_ig_corpus (upsert por shortcode). Engajamento = commentsCount (+videoViewCount em reels).';

    public function handle(): int
    {
        $token = (string) env('APIFY_TOKEN');
        if ($token === '') {
            $this->error('APIFY_TOKEN ausente no .env');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $user = (string) $this->option('username');

        $this->info("Disparando run no ator apify~instagram-post-scraper ({$user}, {$limit} posts)…");
        $run = Http::timeout(30)->post(
            'https://api.apify.com/v2/acts/apify~instagram-post-scraper/runs?token=' . $token,
            ['username' => [$user], 'resultsLimit' => $limit],
        );
        if (! $run->successful()) {
            $this->error('Falha ao iniciar run: HTTP ' . $run->status() . ' ' . mb_substr($run->body(), 0, 200));

            return self::FAILURE;
        }
        $runId = $run->json('data.id');
        $datasetId = $run->json('data.defaultDatasetId');

        // Poll até terminar (timeout ~8min).
        $status = 'RUNNING';
        $custo = null;
        for ($i = 0; $i < 96 && in_array($status, ['RUNNING', 'READY'], true); $i++) {
            sleep(5);
            $info = Http::timeout(20)->get("https://api.apify.com/v2/actor-runs/{$runId}?token={$token}");
            $status = (string) $info->json('data.status');
            $custo = $info->json('data.usageTotalUsd');
        }
        $this->info("Run {$runId}: {$status} · custo US$ " . number_format((float) $custo, 4));
        if ($status !== 'SUCCEEDED') {
            $this->error('Run não concluiu com sucesso.');

            return self::FAILURE;
        }

        $itens = Http::timeout(60)->get(
            "https://api.apify.com/v2/datasets/{$datasetId}/items?token={$token}&clean=true&format=json");
        $posts = $itens->json() ?: [];
        $this->info('Posts recebidos: ' . count($posts));

        $rows = [];
        foreach ($posts as $p) {
            if (empty($p['shortCode'])) {
                continue;
            }
            $rows[] = [
                'shortcode' => $p['shortCode'],
                'url' => $p['url'] ?? ('https://www.instagram.com/p/' . $p['shortCode'] . '/'),
                'tipo_midia' => $p['type'] ?? null,
                'legenda' => mb_substr((string) ($p['caption'] ?? ''), 0, 4000),
                'comments_count' => (int) ($p['commentsCount'] ?? 0),
                'video_view_count' => isset($p['videoViewCount']) ? (int) $p['videoViewCount'] : null,
                'postado_em' => isset($p['timestamp']) ? Carbon::parse($p['timestamp'])->format('Y-m-d H:i:s') : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('jr_ig_corpus')->upsert($chunk, ['shortcode'], [
                'url', 'tipo_midia', 'legenda', 'comments_count', 'video_view_count', 'postado_em', 'updated_at',
            ]);
        }

        $this->info(sprintf('Corpus: %d posts gravados/atualizados · total na tabela: %d · custo do run: US$ %s',
            count($rows), DB::table('jr_ig_corpus')->count(), number_format((float) $custo, 4)));

        return self::SUCCESS;
    }
}
