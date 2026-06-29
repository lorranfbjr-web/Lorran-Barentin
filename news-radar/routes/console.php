<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('news-radar:dispatch')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// FASE 2 — radar de pauta: ponte news_items->jr_link_extracao a cada 30min e o
// juiz LLM logo após (idempotente, cap 300 — custo só do incremental). O timer
// systemd newsradar-scheduler roda schedule:run a cada 5min.
Schedule::command('jrlink:bridge-news')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// CANAL WHATSAPP — ingest cru (idempotente, incremental por cursor: só lê os
// arquivos novos do webhook, não os ~56k do backlog) + ponte captura→Radar
// (release de grupo -> jr_link_extracao origem=whatsapp). A ponte roda :00/:30,
// LOGO ANTES do juiz (:05/:35), pra release novo já entrar no ciclo. O filtro
// de privacidade (só grupo permitido) vive no ingest e na rota de captura.
// NB: cron tem de cair na grade /5 do timer (OnCalendar *:00/5:10), senão o
// schedule:run nunca acha a tarefa "due" — foi o que travou a ponte ('2,32').
Schedule::command('jrpauta:ingest --incremental')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('jrpauta:bridge-radar')
    ->cron('0,30 * * * *')
    ->withoutOverlapping();

Schedule::command('jrlink:juiz --hours=48')
    ->cron('5,35 * * * *')
    ->withoutOverlapping();

Schedule::command('jrlink:instagram-poll')
    ->cron('*/15 * * * *')
    ->withoutOverlapping();

// v4.1 — espelho dos posts publicados (WPGraphQL, só leitura) + marcação
// "já publicado" nos eventos quentes do Radar (some do painel, nunca notifica).
Schedule::command('jrlink:publicados-sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// v5 — agrupamento por assunto (Opus) pra vitrine Radar; roda DEPOIS do juiz,
// no ciclo (nunca no request da página). Custo/latência do Opus vivem aqui.
Schedule::command('jrlink:assuntos')
    ->cron('10,40 * * * *')
    ->withoutOverlapping();

// ── DOM/SC — Radar de Oportunidades (automação aprovada pelo Lorran) ──
// Minera o Diário Oficial dos Municípios de SC (busca pública) atrás de pauta
// de licitação/compras e pontua com Sonnet (editor, não auditor). ISOLADO do
// juiz/radar. Diário e BOUNDED pra ser educado com o portal (que rate-limita
// sob rajada): puxa a janela de 2 dias (idempotente, dedup por ato_id) de
// madrugada e, 1h depois, pontua só os novos (whereNull score) + gera a página.
// Minutos na grade /5 do timer systemd. withoutOverlapping evita pile-up.
Schedule::command('jr:dom-ingest --dias=2 --max-paginas=25')
    ->dailyAt('04:10')
    ->withoutOverlapping();

Schedule::command('jr:dom-oportunidades --lote=8')
    ->dailyAt('05:10')
    ->withoutOverlapping(120);
