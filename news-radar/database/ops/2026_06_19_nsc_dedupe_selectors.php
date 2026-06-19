<?php

/**
 * OPS 2026-06-19 — FIX 2: NSC Total dedupe + seletores de coleta.
 *
 * CONTEXTO: NSC Total estava cadastrado em DOBRO (id=4 non-www, id=67 www),
 * ambos active, ambos com 0 news_items. O html_listing usava a chave morta
 * "selectors" (item/title/link/image) que o ListingDiscoveryService NÃO lê —
 * ele lê listing_item_selectors / listing_link_selectors / etc. Sem essas
 * chaves, caía no default ['article','.post'], que NÃO casa o markup do NSC
 * (WordPress, sem <article> na home) → found=0 em toda run.
 *
 * FIX:
 *   (a) dedupe: desativa id=4 (non-www, duplicado). Mantém id=67 (www).
 *   (b) seletores corretos no id=67 mirando os links reais de notícia
 *       (a[href*="/noticias/"]) — 64 artigos server-renderizados na home.
 *       O título real vem do detalhe (fetch_detail_mode=always), por isso
 *       basta surfar as URLs no listing.
 *   RSS do NSC (/feed/, /rss) está 403 (WAF) — html_listing é o caminho.
 *
 * Idempotente. Backup: /tmp/newsradar-fix-20260619/news_sources_1_4_67.json
 * Rollback: restaurar active=1 no id=4 e o crawling_config antigo do id=67
 *           a partir do backup JSON.
 *
 * Rodar: php artisan tinker --execute="require base_path('database/ops/2026_06_19_nsc_dedupe_selectors.php');"
 */

use Illuminate\Support\Facades\DB;

// (a) dedupe — desativa o cadastro non-www duplicado.
DB::table('news_sources')->where('id', 4)->update([
    'active'     => 0,
    'updated_at' => now(),
]);

// (b) seletores corretos no cadastro www.
$cfg = [
    'listing_urls'           => ['https://www.nsctotal.com.br'],
    'listing_item_selectors' => ['a[href*="/noticias/"]'],
    'listing_link_selectors' => ['a[href*="/noticias/"]'],
    'listing_title_selectors' => ['a[href*="/noticias/"]'],
    'article_url_patterns'   => ['/noticias/'],
];

DB::table('news_sources')->where('id', 67)->update([
    'crawling_config'      => json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    'consecutive_failures' => 0,
    'sync_locked_until'    => null,
    'next_sync_at'         => now(),
    'updated_at'           => now(),
]);

echo "[OPS] NSC id=4 active=" . DB::table('news_sources')->where('id', 4)->value('active')
    . " | id=67 config=" . DB::table('news_sources')->where('id', 67)->value('crawling_config') . "\n";
