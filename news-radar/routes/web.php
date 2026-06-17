<?php

use Illuminate\Support\Facades\Route;

// Vitrine "Radar JR" (server-rendered, lê jr_link_extracao na hora, SEM LLM no
// request). PÚBLICA — sem chave, igual Feed e Fontes (que caem no catch-all SPA
// sem middleware). Só leitura; a escrita (POST /v1/jrlink/feedback) e o painel
// (/v1/jrlink/radar) seguem protegidos pelo JrPanelKey em api.php. Declarada
// ANTES do catch-all do SPA pra não ser engolida.
Route::get('/radar', [\App\Http\Controllers\JrVitrineController::class, 'index']);

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
