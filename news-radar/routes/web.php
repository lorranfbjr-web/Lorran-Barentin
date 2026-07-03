<?php

use Illuminate\Support\Facades\Route;

// Vitrine "Radar JR" (server-rendered, lê jr_link_extracao na hora, SEM LLM no
// request). PÚBLICA — sem chave, igual Feed e Fontes (que caem no catch-all SPA
// sem middleware). Só leitura; a escrita (POST /v1/jrlink/feedback) e o painel
// (/v1/jrlink/radar) seguem protegidos pelo JrPanelKey em api.php. Declarada
// ANTES do catch-all do SPA pra não ser engolida.
// BLOCO 4 (02/07): a vitrine deixou de ser aba solta — vive DENTRO do hub
// /radar-civico (painel 📰 Notícias, iframe ?embed=1). Link antigo redireciona;
// pipeline de notícias (juiz/clusters/assuntos) 100% intacto.
Route::get('/radar', function () {
    if (request()->query('embed') === '1') {
        return app(\App\Http\Controllers\JrVitrineController::class)->index(request());
    }

    return redirect('/radar-civico?painel=noticias', 308);
});

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
// BLOCO 4 (02/07): /dom-radar virou atalho do hub (fonte DOM). /dom-todos e
// /dom-busca seguem como ferramentas específicas (busca Solr ao vivo).
Route::get('/dom-radar', fn () => redirect('/radar-civico?fonte=dom', 308));
Route::get('/dom-busca', [\App\Http\Controllers\DomController::class, 'busca']);
// /oportunidades.html (estático) agora é um stub de redirect gerado pelo
// jr:dom-oportunidades — ver JrDomOportunidades::renderizar.

// RADAR CÍVICO DE SC — Fase 7: une DOM + Câmaras + MPSC + TCE num radar só
// (filtro por fonte + dual-lens + busca). Server-rendered, lê as tabelas ao vivo.
Route::get('/radar-civico', [\App\Http\Controllers\RadarCivicoController::class, 'index']);
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
