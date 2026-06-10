<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('news-radar:dispatch')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// FASE 2 — radar de pauta: ponte news_items->jr_link_extracao a cada 30min e o
// juiz LLM logo após (idempotente, cap 300 — custo só do incremental). O timer
// systemd newsradar-scheduler roda schedule:run a cada 5min.
Schedule::command('jrlink:bridge-news')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('jrlink:juiz --hours=48')
    ->cron('5,35 * * * *')
    ->withoutOverlapping()
    ->runInBackground();
