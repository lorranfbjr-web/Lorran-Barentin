<?php

/*
 * Consulta o SQLite apos o dispatch sincrono e gera /home/jr/smoke-test-report.md.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\NewsRadar\Models\NewsItem;
use App\Modules\NewsRadar\Models\NewsRawItem;
use App\Modules\NewsRadar\Models\NewsSource;
use App\Modules\NewsRadar\Models\NewsSourceRun;
use Illuminate\Support\Facades\DB;

$totalSources = NewsSource::count();
$totalRuns = NewsSourceRun::count();
$totalRaw = NewsRawItem::count();
$totalItems = NewsItem::count();

// runs por status
$runsByStatus = DB::table('news_source_runs')
    ->selectRaw('status, count(*) as c, avg(duration_ms) as avg_dur, sum(items_found) as items_found, sum(items_new) as items_new')
    ->groupBy('status')
    ->orderByDesc('c')
    ->get();

// raw_items por status
$rawByStatus = DB::table('news_raw_items')
    ->selectRaw('processing_status, count(*) as c')
    ->groupBy('processing_status')
    ->orderByDesc('c')
    ->get();

// news_items por extraction_status
$itemsByExtraction = DB::table('news_items')
    ->selectRaw('extraction_status, count(*) as c')
    ->groupBy('extraction_status')
    ->orderByDesc('c')
    ->get();

// news_items por content_source
$itemsByContentSource = DB::table('news_items')
    ->selectRaw('content_source, count(*) as c')
    ->groupBy('content_source')
    ->orderByDesc('c')
    ->get();

// top 10 e bottom 10 fontes por items raspados (raw)
$sourcesRawCount = DB::table('news_sources')
    ->leftJoin('news_raw_items', 'news_raw_items.news_source_id', '=', 'news_sources.id')
    ->selectRaw('news_sources.id, news_sources.name, news_sources.discovery_mode, news_sources.region, count(news_raw_items.id) as raw_count')
    ->groupBy('news_sources.id', 'news_sources.name', 'news_sources.discovery_mode', 'news_sources.region')
    ->get();

$top10 = $sourcesRawCount->sortByDesc('raw_count')->take(10)->values();
$bottom10 = $sourcesRawCount->sortBy('raw_count')->take(10)->values();

// news_items por fonte (promovidos)
$sourcesItemsCount = DB::table('news_sources')
    ->leftJoin('news_items', 'news_items.news_source_id', '=', 'news_sources.id')
    ->selectRaw('news_sources.name, count(news_items.id) as items_count')
    ->groupBy('news_sources.name')
    ->orderByDesc('items_count')
    ->get();

// amostra de 5 items — uma por fonte distinta pra ser representativa
$sample = collect(DB::select("
    SELECT ni.title, ni.url, ni.published_at_utc, ni.content_source, ni.extraction_completeness, ns.name AS source_name
    FROM news_items ni
    JOIN news_sources ns ON ni.news_source_id = ns.id
    GROUP BY ns.id
    ORDER BY RANDOM()
    LIMIT 5
"))->map(fn($r) => (object) (array) $r);

// run errors
$runErrors = DB::table('news_source_runs')
    ->join('news_sources', 'news_source_runs.news_source_id', '=', 'news_sources.id')
    ->whereNotNull('error_message')
    ->where('error_message', '!=', '')
    ->select('news_sources.name', 'news_source_runs.status', 'news_source_runs.error_message', 'news_source_runs.duration_ms')
    ->orderBy('news_sources.name')
    ->get();

// process errors (raw items failed/skipped)
$rawFailed = DB::table('news_raw_items')
    ->join('news_sources', 'news_raw_items.news_source_id', '=', 'news_sources.id')
    ->whereIn('processing_status', ['failed', 'skipped'])
    ->selectRaw('news_sources.name, news_raw_items.processing_status, count(*) as c')
    ->groupBy('news_sources.name', 'news_raw_items.processing_status')
    ->orderByDesc('c')
    ->limit(20)
    ->get();

// ultimos erros do log
$logPath = __DIR__ . '/../storage/logs/laravel.log';
$logErrors = [];
if (file_exists($logPath)) {
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES) ?: [];
    $sampleErrors = array_filter($lines, fn($l) => stripos($l, '[NewsRadar]') !== false && stripos($l, 'failed') !== false);
    $logErrors = array_slice(array_values($sampleErrors), -30);
}

// Montagem do markdown
$md = "# Smoke test — NewsRadar\n\n";
$md .= "Gerado: " . date('Y-m-d H:i:s') . "\n\n";
$md .= "## 1. Totais\n\n";
$md .= "| Metrica | Valor |\n|---|---|\n";
$md .= "| Fontes cadastradas | $totalSources |\n";
$md .= "| Runs executadas | $totalRuns |\n";
$md .= "| Raw items coletados | $totalRaw |\n";
$md .= "| News items promovidos | $totalItems |\n";
$md .= "| Taxa de promocao | " . ($totalRaw > 0 ? round($totalItems / $totalRaw * 100, 1) . '%' : 'n/a') . " |\n\n";

$md .= "## 2. Runs por status\n\n";
$md .= "| Status | Qtd | Avg duration (ms) | Items found | Items new |\n|---|---|---|---|---|\n";
foreach ($runsByStatus as $r) {
    $md .= sprintf("| %s | %d | %d | %d | %d |\n",
        $r->status, $r->c, (int) $r->avg_dur, $r->items_found, $r->items_new);
}

$md .= "\n## 3. Raw items por status\n\n";
$md .= "| Status | Qtd |\n|---|---|\n";
foreach ($rawByStatus as $r) {
    $md .= sprintf("| %s | %d |\n", $r->processing_status, $r->c);
}

$md .= "\n## 4. News items por extraction_status\n\n";
$md .= "| Status | Qtd |\n|---|---|\n";
foreach ($itemsByExtraction as $r) {
    $md .= sprintf("| %s | %d |\n", $r->extraction_status, $r->c);
}

$md .= "\n## 5. News items por content_source\n\n";
$md .= "| Content source | Qtd |\n|---|---|\n";
foreach ($itemsByContentSource as $r) {
    $md .= sprintf("| %s | %d |\n", $r->content_source, $r->c);
}

$md .= "\n## 6. Top 10 fontes por items raspados\n\n";
$md .= "| # | Fonte | Regiao | Modo | Raw items |\n|---|---|---|---|---|\n";
$i = 1;
foreach ($top10 as $s) {
    $md .= sprintf("| %d | %s | %s | %s | %d |\n", $i++, $s->name, $s->region ?? '-', $s->discovery_mode, $s->raw_count);
}

$md .= "\n## 7. Bottom 10 fontes por items raspados (zero ou poucos)\n\n";
$md .= "| # | Fonte | Regiao | Modo | Raw items |\n|---|---|---|---|---|\n";
$i = 1;
foreach ($bottom10 as $s) {
    $md .= sprintf("| %d | %s | %s | %s | %d |\n", $i++, $s->name, $s->region ?? '-', $s->discovery_mode, $s->raw_count);
}

$md .= "\n## 8. News items promovidos por fonte\n\n";
$md .= "| Fonte | Promovidos |\n|---|---|\n";
foreach ($sourcesItemsCount as $s) {
    $md .= sprintf("| %s | %d |\n", $s->name, $s->items_count);
}

$md .= "\n## 9. Amostra de 5 news items\n\n";
foreach ($sample as $it) {
    $publishedAt = $it->published_at_utc ?? 'null';
    $title = mb_strlen($it->title ?? '') > 140 ? mb_substr($it->title, 0, 140) . '…' : ($it->title ?? '(sem titulo)');
    $md .= "- **" . $title . "**\n";
    $md .= "  - fonte: " . $it->source_name . "\n";
    $md .= "  - url: " . $it->url . "\n";
    $md .= "  - published_at_utc: " . $publishedAt . "\n";
    $md .= "  - content_source: " . ($it->content_source ?? '-') . "\n";
    $md .= "  - extraction_completeness: " . ($it->extraction_completeness ?? 0) . "\n\n";
}

$md .= "## 10. Erros de run (fonte falhou no fetch)\n\n";
if ($runErrors->isEmpty()) {
    $md .= "_Nenhum run com error_message._\n";
} else {
    $md .= "| Fonte | Status | Duracao (ms) | Erro |\n|---|---|---|---|\n";
    foreach ($runErrors as $e) {
        $err = str_replace(['|', "\n"], ['\\|', ' '], $e->error_message ?? '');
        if (mb_strlen($err) > 200) $err = mb_substr($err, 0, 200) . '…';
        $md .= sprintf("| %s | %s | %d | %s |\n", $e->name, $e->status, $e->duration_ms, $err);
    }
}

// Categorizacao dos erros do log (amostra das ultimas ~30 linhas)
$errorCategories = [];
foreach ($logErrors as $line) {
    if (stripos($line, 'Undefined array key "author"') !== false) {
        $k = 'Undefined array key "author" (bug pipeline)';
    } elseif (stripos($line, 'cURL error 28') !== false) {
        $k = 'HTTP timeout (cURL 28)';
    } elseif (stripos($line, '403') !== false) {
        $k = 'HTTP 403 (bot bloqueado)';
    } elseif (stripos($line, 'DomCrawler') !== false) {
        $k = 'Dom-Crawler nao instalado';
    } elseif (stripos($line, 'XML') !== false || stripos($line, 'parser error') !== false) {
        $k = 'Parse XML invalido';
    } else {
        $k = 'Outros';
    }
    $errorCategories[$k] = ($errorCategories[$k] ?? 0) + 1;
}
arsort($errorCategories);

$md .= "\n## 11. Erros mais comuns (categorizados a partir das ultimas linhas do log)\n\n";
if (empty($errorCategories)) {
    $md .= "_Nenhum erro no log._\n";
} else {
    $md .= "| Categoria | Ocorrencias na amostra |\n|---|---|\n";
    foreach ($errorCategories as $cat => $n) {
        $md .= sprintf("| %s | %d |\n", $cat, $n);
    }
    $md .= "\n> Amostra sao as ultimas ~30 linhas com `[NewsRadar] ... failed`, nao o total absoluto.\n";
}

$md .= "\n## 12. Raw items failed/skipped por fonte\n\n";
if ($rawFailed->isEmpty()) {
    $md .= "_Nenhum raw item failed ou skipped._\n";
} else {
    $md .= "| Fonte | Status | Qtd |\n|---|---|---|\n";
    foreach ($rawFailed as $e) {
        $md .= sprintf("| %s | %s | %d |\n", $e->name, $e->processing_status, $e->c);
    }
}

$md .= "\n## 13. Ultimos erros no log (laravel.log) — bruto\n\n";
if (empty($logErrors)) {
    $md .= "_Nenhum erro com prefixo [NewsRadar] ... failed no log._\n";
} else {
    $md .= "```\n";
    foreach ($logErrors as $line) {
        $md .= substr($line, 0, 400) . "\n";
    }
    $md .= "```\n";
}

file_put_contents('/home/jr/smoke-test-report.md', $md);
echo "OK: /home/jr/smoke-test-report.md\n";
echo "Totais: sources=$totalSources runs=$totalRuns raw=$totalRaw items=$totalItems\n";
