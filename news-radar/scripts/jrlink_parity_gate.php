<?php

/**
 * HARD GATE DE PARIDADE — caminho WhatsApp do jrlink.
 *
 * Recomputa a classificação coarse (host/categoria/status/eixo/temperatura/score)
 * de TODAS as rows origem=whatsapp via PautaClassifier e compara com o que está
 * gravado em jr_link_extracao. Qualquer divergência = exit 1 (gate reprovado).
 *
 * Uso: php scripts/jrlink_parity_gate.php
 */

use App\Services\Jr\PautaClassifier;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$clf = new PautaClassifier();

$rows = DB::table('jr_link_extracao')
    ->where(function ($q) {
        $q->where('origem', 'whatsapp')->orWhereNull('origem');
    })
    ->orderBy('id')
    ->get();

$diff = 0;
$total = 0;

foreach ($rows as $r) {
    $total++;
    $host = $clf->resolveHost($r->url, $r->markdown);
    $categoria = $clf->categoria($host);
    $corpo = $clf->corpoFromMarkdown($r->markdown);
    $cats = $clf->categoriesFromMarkdown($r->markdown);
    $status = $clf->gateStatus($categoria, $r->titulo, $corpo);
    [$eixo, $temperatura, $score] = $clf->temperatura($categoria, $r->titulo, $corpo, $cats, $r->url);

    $esperado = [
        'categoria' => $categoria,
        'status' => $status,
        'eixo' => $eixo,
        'temperatura' => $temperatura,
        'score' => $score,
    ];
    $gravado = [
        'categoria' => $r->categoria,
        'status' => $r->status,
        'eixo' => $r->eixo,
        'temperatura' => $r->temperatura,
        'score' => (int) $r->score,
    ];

    if ($esperado !== $gravado) {
        $diff++;
        fwrite(STDERR, sprintf(
            "DIVERGÊNCIA #%d %s\n  esperado: %s\n  gravado.: %s\n",
            $r->id,
            mb_strimwidth((string) $r->titulo, 0, 70),
            json_encode($esperado, JSON_UNESCAPED_UNICODE),
            json_encode($gravado, JSON_UNESCAPED_UNICODE),
        ));
    }
}

if ($diff > 0) {
    fwrite(STDERR, "\nGATE DE PARIDADE: REPROVADO — {$diff}/{$total} rows divergem do PautaClassifier.\n");
    exit(1);
}

echo "GATE DE PARIDADE: OK — {$total}/{$total} rows WhatsApp batem com o PautaClassifier.\n";
exit(0);
