<?php

/**
 * OPS 2026-06-19 — FIX 1: religar ND Mais (news_sources id=1).
 *
 * CONTEXTO: o feed https://ndmais.com.br/feed/ devolveu XML inválido
 * (XML_ERR_NAME_REQUIRED) por ~1 dia em 29-30/05/2026 → 5 falhas seguidas →
 * auto-desativação (active=0). O feed se recuperou (HTTP 200, RSS válido, 20
 * itens), mas a fonte ficou ~20 dias offline. Este script religa.
 *
 * SEGURO: a ponte jrlink:bridge-news é limitada por --hours (default 48h) via
 * published_at_utc; os 9.213 news_items históricos (até 29/05) NÃO re-entram.
 * Dedup por url_hash impede re-ingestão dos antigos — só artigos frescos entram.
 *
 * Idempotente. Backup do estado anterior: /tmp/newsradar-fix-20260619/news_sources_1_4_67.json
 * Rollback: UPDATE news_sources SET active=0 WHERE id=1;
 *
 * Rodar: php artisan tinker --execute="require base_path('database/ops/2026_06_19_nd_reativar.php');"
 */

use Illuminate\Support\Facades\DB;

DB::table('news_sources')->where('id', 1)->update([
    'active'               => 1,
    'consecutive_failures' => 0,
    'sync_locked_until'    => null,
    'next_sync_at'         => now(),
    'updated_at'           => now(),
]);

echo "[OPS] ND Mais (id=1) religado: active=" . DB::table('news_sources')->where('id', 1)->value('active') . "\n";
