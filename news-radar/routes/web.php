<?php

use Illuminate\Support\Facades\Route;

// Vitrine "Radar JR" (server-rendered, lê jr_link_extracao na hora, SEM LLM no
// request). PÚBLICA — sem chave, igual Feed e Fontes (que caem no catch-all SPA
// sem middleware). Só leitura; a escrita (POST /v1/jrlink/feedback) e o painel
// (/v1/jrlink/radar) seguem protegidos pelo JrPanelKey em api.php. Declarada
// ANTES do catch-all do SPA pra não ser engolida.
// GOAL SIMPLIFICAR (03/07): o hub-abas falhou no uso real (iframe travava no
// celular) — /radar VOLTA a ser a página própria da vitrine, standalone.
// ?embed=1 segue aceito (links antigos), renderiza a mesma vitrine.
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

// Radar de Oportunidades DOM/SC — 3 páginas públicas server-rendered (leem
// jr_dom_atos ao vivo; /dom-busca consulta o Solr do DOM ao vivo). Subsistema
// ISOLADO (não toca juiz/radar editorial). Antes do catch-all do SPA.
Route::get('/dom-todos', [\App\Http\Controllers\DomController::class, 'todos']);
// GOAL SIMPLIFICAR (03/07): /dom-radar aponta pra página nova /dom.
Route::get('/dom-radar', fn () => redirect('/dom', 308));
Route::get('/dom-busca', [\App\Http\Controllers\DomController::class, 'busca']);
// /oportunidades.html (estático) agora é um stub de redirect gerado pelo
// jr:dom-oportunidades — ver JrDomOportunidades::renderizar.

// RADAR CÍVICO DE SC — GOAL SIMPLIFICAR (03/07): páginas INDEPENDENTES por
// fonte (leves, server-rendered, sem iframe) + /radar-civico como ÍNDICE
// minimalista. Links antigos (?fonte=, ?painel=) redirecionam no index().
Route::get('/radar-civico', [\App\Http\Controllers\RadarCivicoController::class, 'index']);
Route::get('/dom', [\App\Http\Controllers\RadarCivicoController::class, 'pagina'])->defaults('slug', 'dom');
Route::get('/camaras', [\App\Http\Controllers\RadarCivicoController::class, 'pagina'])->defaults('slug', 'camaras');
Route::get('/justica', [\App\Http\Controllers\RadarCivicoController::class, 'pagina'])->defaults('slug', 'justica');
Route::get('/prefeituras', [\App\Http\Controllers\RadarCivicoController::class, 'pagina'])->defaults('slug', 'prefeituras');
// Fase 2 — íntegra do ato (lazy, read-only): texto_bruto do trecho daquele assunto.
Route::get('/radar-civico/ato/{source}/{id}', [\App\Http\Controllers\RadarCivicoController::class, 'ato'])
    ->where('source', 'dom|camara|mpsc|tce|prefeitura')->where('id', '\d+');

// MESA DE PAUTA — Fase 1: triagem + fila de produção server-side (cross-device).
// ATRÁS de JrPanelKey (cookie do painel, mesma chave do Radar): a fila é do
// Lorran. ISENTA de CSRF (mesa/* em bootstrap/app.php). NÃO publica nada.
// O botão ★ do /radar-civico só funciona depois de armar o cookie visitando
// /mesa?key=<JRLINK_PANEL_KEY> uma vez (cookie de 90 dias, path '/').
Route::middleware(\App\Http\Middleware\JrPanelKey::class)->group(function () {
    Route::get('/mesa', [\App\Http\Controllers\MesaPautaController::class, 'index']);
    Route::get('/mesa/contagem', [\App\Http\Controllers\MesaPautaController::class, 'contagem']);
    Route::post('/mesa/selecionar', [\App\Http\Controllers\MesaPautaController::class, 'selecionar']);
    Route::post('/mesa/{id}', [\App\Http\Controllers\MesaPautaController::class, 'atualizar'])->where('id', '\d+');
    Route::post('/mesa/{id}/remover', [\App\Http\Controllers\MesaPautaController::class, 'remover'])->where('id', '\d+');
    Route::post('/mesa/{id}/rascunho', [\App\Http\Controllers\MesaPautaController::class, 'rascunho'])->where('id', '\d+');
});

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
