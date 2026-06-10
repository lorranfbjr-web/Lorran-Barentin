<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Module routes are loaded via their own ServiceProvider

// ====================================================================
// PASSO ZERO — CAPTURA JR PAUTA (TEMPORÁRIO / DESCARTÁVEL)
// Recebe o webhook "ao receber" do Z-API (instância WhatsRaspador),
// salva o payload cru em arquivo e responde 200.
// Rota em api.php => fora do CSRF (middleware api é stateless).
// Remover quando a inspeção terminar.
// ====================================================================
Route::post('/jr-pauta-capture', function (Request $request) {
    $dir = storage_path('app/jr-pauta-capture');
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $name = now()->format('Ymd-His') . '-' . Str::lower(Str::random(4)) . '.json';
    $path = $dir . '/' . $name;

    $payload = [
        'received_at' => now()->toIso8601String(),
        'ip'          => $request->ip(),
        'method'      => $request->method(),
        'headers'     => $request->headers->all(),
        'query'       => $request->query(),
        'all'         => $request->all(),       // payload parseado
        'raw_body'    => $request->getContent(), // conteúdo bruto
    ];

    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    Log::info('JR-PAUTA-CAPTURE salvo: ' . $path);

    return response()->json(['ok' => true]);
});

// ── Aba Radar do painel (JR Pauta / juiz) — atrás de chave leve ──
Route::prefix('v1/jrlink')
    ->middleware(\App\Http\Middleware\JrPanelKey::class)
    ->group(function () {
        Route::get('/radar', [\App\Http\Controllers\JrRadarController::class, 'index']);
        Route::post('/feedback', [\App\Http\Controllers\JrFeedbackController::class, 'store']);
    });
