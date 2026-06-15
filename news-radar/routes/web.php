<?php

use Illuminate\Support\Facades\Route;

// Vitrine "Radar JR" (server-rendered, lê jr_link_extracao na hora, SEM LLM no
// request). Atrás da MESMA chave leve do painel (?key=… → cookie 90d). Declarada
// ANTES do catch-all do SPA pra não ser engolida.
Route::get('/radar', [\App\Http\Controllers\JrVitrineController::class, 'index'])
    ->middleware(\App\Http\Middleware\JrPanelKey::class);

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
