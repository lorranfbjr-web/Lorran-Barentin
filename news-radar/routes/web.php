<?php

use Illuminate\Support\Facades\Route;

// Vitrine "Radar JR" (server-rendered, lê jr_link_extracao na hora, SEM LLM no
// request). PÚBLICA — sem chave, igual Feed e Fontes (que caem no catch-all SPA
// sem middleware). Só leitura; a escrita (POST /v1/jrlink/feedback) e o painel
// (/v1/jrlink/radar) seguem protegidos pelo JrPanelKey em api.php. Declarada
// ANTES do catch-all do SPA pra não ser engolida.
Route::get('/radar', [\App\Http\Controllers\JrVitrineController::class, 'index']);

// Goal 3 — ferramenta de produção. Container (texto dos portais + links, sem
// LLM) e reescrita unificada (LLM, manual). ATRÁS de JrPanelKey: expõe texto de
// concorrente e dispara LLM — privado do Lorran (chave do painel). NÃO publica
// nada, só lê o banco e grava jr_pauta_reescrita.
Route::middleware(\App\Http\Middleware\JrPanelKey::class)->group(function () {
    Route::get('/radar/assunto/{assuntoId}', [\App\Http\Controllers\JrReescritaController::class, 'mostrar'])
        ->where('assuntoId', '[ai]\w+');
    Route::post('/radar/assunto/{assuntoId}/reescrever', [\App\Http\Controllers\JrReescritaController::class, 'reescrever'])
        ->where('assuntoId', '[ai]\w+');

    // Goal 4 — verificador de pauta (cola texto/link → veredito + lacunas + quem publicou).
    Route::get('/radar/verificar', [\App\Http\Controllers\JrVerificadorController::class, 'form']);
    Route::post('/radar/verificar', [\App\Http\Controllers\JrVerificadorController::class, 'verificar']);
});

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
