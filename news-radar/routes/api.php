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
    // FILTRO DE PRIVACIDADE (Parte A): só mensagem de GRUPO permitido vira
    // arquivo. Conversa individual e grupos da denylist (pessoais / #JRxx /
    // 'Raspador' anti-loop) são descartados ANTES de tocar o disco — responde
    // 200 igual pro Z-API não reenfileirar.
    $isGroup  = filter_var($request->input('isGroup', false), FILTER_VALIDATE_BOOLEAN);
    $chatName = $request->input('chatName');
    if (! \App\Services\Jr\CapturaFiltro::aceita($chatName, $isGroup)) {
        return response()->json(['ok' => true, 'skipped' => 'privacy_filter']);
    }

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

// ── BLOCO 2 (03/07) — APROVAÇÃO POR ✅ NO WHATSAPP (webhook da instância de
// ALERTA "3…", NUNCA 276/884). Rota ADITIVA, fora do CSRF (api stateless),
// protegida por token secreto no path (fail-closed: sem env, 404 sempre).
// Reply/reação ✅ no grupo RASCUNHOS → DRAFT no WP (nunca publica) → link no
// grupo. ❌ → descarta e registra feedback. Ver ZapAprovacaoController.
Route::post('/jr/zap-alerta-hook/{token}', \App\Http\Controllers\ZapAprovacaoController::class);
