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

// ── DOM/SC — Radar de Oportunidades (PRODUÇÃO 24/7, aprovada pelo Lorran) ──
// Minera o Diário Oficial dos Municípios de SC (busca pública) atrás de pauta de
// licitação/compras e pontua com Sonnet (editor, não auditor). ISOLADO do juiz/
// radar. EDUCADO com o portal (pausa entre páginas; o portal rate-limita rajada).
// Todos os minutos caem na grade /5 do timer systemd (senão schedule:run nunca
// acha a tarefa "due"). withoutOverlapping (lock próprio por comando) evita
// pile-up e mantém forward e retroativo sem se atropelarem.
//
// (1) FORWARD — prioridade: a cada 15min puxa a janela curta (2 dias) e PARA de
//     paginar assim que bate no que já temos (--parar-vistos), então é barato e
//     só captura o que é novo. É o que mantém a base "daqui pra frente".
Schedule::command('jr:dom-ingest --dias=2 --max-paginas=25 --parar-vistos=20')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// (2) SCORING — contínuo e incremental: pontua só os atos novos (whereNull score)
//     com Sonnet (dual-lens 🔴/🟢) e regenera a página. Offset 5min pra rodar
//     LOGO DEPOIS do forward, no mesmo ciclo. Lock de 600s (chamada LLM é lenta).
// --limit=24: execução CURTA (3 chamadas/ciclo) — mantém o FORWARD em dia sem o
// backlog histórico (30k+ atos do retroativo) sufocar o claude-cli. Horário 20,50
// NÃO coincide com o juiz (5,35), que tem prioridade na assinatura Max. (Backlog
// histórico se scora em janelas manuais controladas, não no horário de produção.)
Schedule::command('jr:dom-oportunidades --lote=8 --limit=24')
    ->cron('20,50 * * * *')
    ->withoutOverlapping(600);

// (3) RETROATIVO — background, baixa prioridade: a cada 30min baixa UM chunk do
//     histórico pra trás (cursor persistido, resume) até a profundidade-alvo
//     (60 dias). Offset 10/40 pra não coincidir com o forward (0/15/30/45). Não
//     compete: lock próprio + pausa educada; quando alcança o alvo, vira no-op.
Schedule::command('jr:dom-retroativo --chunks=1')
    ->cron('10,40 * * * *')
    ->withoutOverlapping(600);

// ── RADAR CÍVICO Fase 2 — CÂMARAS (SAPL) — proposições legislativas ──
// ADITIVO/ISOLADO (não toca DOM/juiz/captura). Só 2 das 5 câmaras com SAPL são
// vivas (São Bento do Sul, Rio do Sul); nelas o forward pega proposição nova, nas
// stale vira no-op (esperado). Grade /5 do timer + withoutOverlapping.
//
// (1) FORWARD — de hora em hora puxa o ano corrente de cada câmara e PARA por
//     tipo ao bater no que já temos (early-stop). Raro mudar => cadência leve.
Schedule::command('jr:camara-ingest --parar-vistos=20')
    ->cron('25 * * * *')
    ->withoutOverlapping(600);

// (2) FARO — pontua só as proposições novas (whereNull score) com Sonnet
//     (dual-lens 🔴/🟢, lente câmara). Offset pra rodar logo após o forward.
Schedule::command('jr:camara-score')
    ->cron('35 * * * *')
    ->withoutOverlapping(600);

// ── RADAR CÍVICO Fase 4 — MPSC (DOE PDF diário) — instaurações de procedimentos ──
// ADITIVO/ISOLADO. O DOE-MPSC sai Seg-Sex; o forward varre os últimos dias úteis
// (fim de semana = 404, pula). Parsing via PyMuPDF (venv dedicado). Grade /5.
//
// (1) FORWARD — 1×/dia de manhã puxa a janela curta de dias úteis e dedup.
Schedule::command('jr:mpsc-ingest --dias=3')
    ->cron('45 8 * * 1-6')
    ->withoutOverlapping(600);

// (2) FARO — pontua os extratos novos (Sonnet, dual-lens, lente MPSC).
Schedule::command('jr:mpsc-score')
    ->cron('55 8 * * 1-6')
    ->withoutOverlapping(600);

// ── RADAR CÍVICO Fase 5 — TCE-SC (DOTC-e PDF diário) — decisões/julgamentos ──
// ADITIVO/ISOLADO. DOTC-e sai Seg-Sex; forward varre dias úteis (404 no fds).
// PDF datado direto (o índice tem shield anti-bot). Parsing PyMuPDF. Grade /5.
Schedule::command('jr:tce-ingest --dias=3')
    ->cron('50 9 * * 1-6')
    ->withoutOverlapping(600);

Schedule::command('jr:tce-score')
    ->cron('0 10 * * 1-6')
    ->withoutOverlapping(600);

// ── MESA DE PAUTA Fase 4 — ALERTA das pautas quentes no Telegram ──
// ADITIVO/ISOLADO. Digest por ciclo (anti-flood) das pautas quentes E novas;
// dedup em jr_civico_alertas. FAIL-CLOSED: sem TELEGRAM_BOT_TOKEN +
// RADAR_CIVICO_ALERT_CHAT_ID no .env, NÃO envia (só loga) — então deixar
// agendado é inócuo até o Lorran configurar o alvo. Grade /5 do timer.
Schedule::command('jrcivico:alertar')
    ->everyFiveMinutes()
    ->withoutOverlapping(600);
