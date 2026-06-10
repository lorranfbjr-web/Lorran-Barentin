<?php

/**
 * ACEITE DA FASE 2 — verificação automática dos 4 critérios:
 *  a) nacional morto: Enem/Lula/ONU/veto carne UE = frio no veredito final;
 *  b) score graduado: distribuição não satura num valor único; primária forte
 *     (Itajaí 166 anos) acima de fait-divers fraco (formigas na cozinha);
 *  c) dedup de evento: oceanógrafo aparece 1x nos quentes finais;
 *  d) SEO-washing ("tudo o que se sabe", "como foi", "o que aconteceu") =
 *     eh_pauta false.
 *
 * Exit 0 = todos passam; exit 1 = lista o que falhou.
 */

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$falhas = [];
$tempFinal = "coalesce(temperatura_juiz, temperatura)";

// ── (a) nacional morto ─────────────────────────────────────────────
$nacionais = DB::table('jr_link_extracao')
    ->where('duplicada', false)
    ->where(function ($q) {
        $q->where('titulo', 'like', '%Enem%')
            ->orWhere('titulo', 'like', '%Lula %')
            ->orWhere('titulo', 'like', '% Lula%')
            ->orWhere('titulo', 'like', '% ONU %')
            ->orWhere(function ($qq) {
                $qq->where('titulo', 'like', '%carne%')->where('titulo', 'like', '%Europ%');
            });
    })
    ->get();
$aQuentes = $nacionais->filter(fn ($r) => ($r->temperatura_juiz ?? $r->temperatura) === 'quente'
    // mesma semântica da saída do pipeline: só representante (ou sem cluster) aparece
    && ($r->cluster_rep || $r->cluster_id === null)
    // ângulo local real legitima (nacional_localizado julgado pauta não conta como falha)
    && ! ($r->escopo === 'nacional_localizado' && $r->eh_pauta));
echo sprintf("(a) nacional morto: %d itens Enem/Lula/ONU/carne-UE · %d quentes ilegítimos\n",
    $nacionais->count(), $aQuentes->count());
foreach ($aQuentes as $r) {
    echo "    FALHA #{$r->id} [{$r->escopo}] " . mb_strimwidth((string) $r->titulo, 0, 80) . "\n";
    $falhas[] = 'a';
}

// ── (b) score graduado ─────────────────────────────────────────────
$quentes = DB::table('jr_link_extracao')
    ->whereRaw("$tempFinal = 'quente'")->where('duplicada', false)
    ->where(function ($q) {
        $q->where('cluster_rep', true)->orWhereNull('cluster_id');
    })
    ->whereNotNull('score_editorial')
    ->pluck('score_editorial');
$distintos = $quentes->unique()->count();
$moda = $quentes->countBy()->sortDesc()->first() ?? 0;
$pctModa = $quentes->count() ? round(100 * $moda / $quentes->count()) : 0;
echo sprintf("(b) score graduado: %d quentes julgados · %d valores distintos · moda cobre %d%%\n",
    $quentes->count(), $distintos, $pctModa);
if ($quentes->count() > 0 && ($distintos < 5 || $pctModa > 50)) {
    echo "    FALHA: distribuição saturada\n";
    $falhas[] = 'b';
}

$itajai = DB::table('jr_link_extracao')->where('titulo', 'like', '%Itajaí 166%')->where('duplicada', false)->first();
$formigas = DB::table('jr_link_extracao')->where('titulo', 'like', '%formigas na cozinha%')->where('duplicada', false)->first();
if ($itajai && $formigas) {
    echo sprintf("    Itajaí 166 anos: score_editorial=%s (%s) · formigas: score_editorial=%s (%s)\n",
        $itajai->score_editorial ?? 'null', $itajai->temperatura_juiz ?? 'sem juiz',
        $formigas->score_editorial ?? 'null', $formigas->temperatura_juiz ?? 'sem juiz');
    if ($itajai->score_editorial === null || $formigas->score_editorial === null
        || (int) $itajai->score_editorial <= (int) $formigas->score_editorial) {
        echo "    FALHA: Itajaí 166 anos não está acima de formigas na cozinha\n";
        $falhas[] = 'b';
    }
} else {
    echo "    AVISO: exemplo Itajaí-166/formigas fora da base atual (não conta como falha)\n";
}

// ── (c) dedup de evento (oceanógrafo) ──────────────────────────────
$oceano = DB::table('jr_link_extracao')
    ->where(function ($q) {
        $q->where('titulo', 'like', '%oceanógrafo%')->orWhere('titulo', 'like', '%Gorri%');
    })
    ->where('duplicada', false)->get();
$oceanoQuentesFinais = $oceano->filter(fn ($r) => ($r->temperatura_juiz ?? $r->temperatura) === 'quente'
    && ($r->cluster_rep || $r->cluster_id === null));
$nClusters = $oceano->pluck('cluster_id')->filter()->unique()->count();
echo sprintf("(c) dedup evento: oceanógrafo %d itens · %d cluster(s) · %d nos quentes finais\n",
    $oceano->count(), $nClusters, $oceanoQuentesFinais->count());
if ($oceano->count() > 1 && ($oceanoQuentesFinais->count() > 1 || $nClusters > 1)) {
    echo "    FALHA: história repetida não colapsou pra 1\n";
    $falhas[] = 'c';
}

// ── (d) SEO-washing ────────────────────────────────────────────────
$seo = DB::table('jr_link_extracao')
    ->where('duplicada', false)
    ->whereNotNull('juiz_julgado_em')
    ->where(function ($q) {
        $q->where('titulo', 'like', 'Tudo o que se sabe%')
            ->orWhere('titulo', 'like', 'Como foi%')
            ->orWhere('titulo', 'like', 'O que aconteceu%')
            ->orWhere('titulo', 'like', 'O que se sabe%');
    })
    ->get();
$seoPauta = $seo->filter(fn ($r) => (bool) $r->eh_pauta);
echo sprintf("(d) SEO-washing: %d títulos-SEO julgados · %d com eh_pauta=true\n", $seo->count(), $seoPauta->count());
foreach ($seoPauta as $r) {
    echo "    FALHA #{$r->id} " . mb_strimwidth((string) $r->titulo, 0, 80) . "\n";
    $falhas[] = 'd';
}

echo "\n" . ($falhas
    ? 'ACEITE: REPROVADO nos critérios ' . implode(',', array_unique($falhas)) . "\n"
    : "ACEITE: TODOS OS CRITÉRIOS PASSARAM\n");
exit($falhas ? 1 : 0);
