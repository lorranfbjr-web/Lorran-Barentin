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
// (release de grupo -> jr_link_extracao origem=whatsapp). A ponte roda :02/:32,
// LOGO ANTES do juiz (:05/:35), pra release novo já entrar no ciclo. O filtro
// de privacidade (só grupo permitido) vive no ingest e na rota de captura.
Schedule::command('jrpauta:ingest --incremental')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('jrpauta:bridge-radar')
    ->cron('2,32 * * * *')
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
