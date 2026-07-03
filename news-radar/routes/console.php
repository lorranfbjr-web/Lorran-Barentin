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

// INSTAGRAM: ABANDONADO (01/07/2026, decisão do Lorran). O Apify retorna HTTP 403
// (corpus IG @jornalrazao quebrado) e o poll falhava a cada 15min poluindo o log.
// Desagendado — reabilitar só se trocar a fonte de coleta do IG.
// Schedule::command('jrlink:instagram-poll')->cron('*/15 * * * *')->withoutOverlapping();

// v4.1 — espelho dos posts publicados (WPGraphQL, só leitura) + marcação
// "já publicado" nos eventos quentes do Radar (some do painel, nunca notifica).
Schedule::command('jrlink:publicados-sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// v5 — agrupamento por assunto (gpt-4o-mini desde 30/06 — operação mecânica, zero
// Max) pra vitrine Radar; roda DEPOIS do juiz, no ciclo (nunca no request).
Schedule::command('jrlink:assuntos')
    ->cron('10,40 * * * *')
    ->withoutOverlapping();

// SEGUNDO OLHAR — 2ª opinião do gpt-4o-mini (CEGO, zero Max) que marca DIVERGÊNCIA
// pra revisão humana na Mesa. Não toca o veredito primário. Roda no ciclo.
// (a) JUIZ de notícias: nos quentes recentes, com o mesmo prompt do juiz.
Schedule::command('jr:segundo-olhar-juiz --dias=3 --lote=8')
    ->cron('15,45 * * * *')
    ->withoutOverlapping(600);

// (b) RADAR CÍVICO: nos candidatos a pauta (score>=min) de cada fonte. --limit
//     mantém a execução curta; roda logo após cada faro.
Schedule::command('jr:segundo-olhar dom --limit=60')
    ->cron('55 * * * *')
    ->withoutOverlapping(600);
Schedule::command('jr:segundo-olhar camara --limit=60')
    ->cron('40 * * * *')
    ->withoutOverlapping(600);
Schedule::command('jr:segundo-olhar mpsc --limit=60')
    ->cron('5 9 * * 1-6')
    ->withoutOverlapping(600);
Schedule::command('jr:segundo-olhar tce --limit=60')
    ->cron('10 10 * * 1-6')
    ->withoutOverlapping(600);

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

// (2) SCORING — SÓ PRA FRENTE: pontua os atos novos (whereNull) dos últimos
//     dom.scoring.forward_dias com Sonnet (dual-lens 🔴/🟢) e regenera a página.
//     CAPACIDADE (fix A5, 02/07): 48 ciclos × 24 = 1.152/dia < inflow 2.000–2.600
//     ⇒ excedente morria sem score em 7 dias. Agora */10 × --limit=32 =
//     4.608/dia nominal (≥3.000 com folga p/ tick pulado). O lote FICA em 8:
//     chamada curta nunca estoura o Process::timeout(600) do scorer (o exit 143
//     visto era lote grande numa chamada só). */10 não coincide com o juiz (5,35).
Schedule::command('jr:dom-oportunidades --lote=8 --limit=32')
    ->cron('*/10 * * * *')
    ->withoutOverlapping(600);

// RETROATIVO: REMOVIDO (30/06/2026). Decisão do Lorran: só pra frente, sem
// histórico. O crawler jr:dom-retroativo existe mas não é mais agendado.

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

// (3) LEGISLADOR WEB (02/07, goal radar-interesse) — 7 câmaras de interesse
//     (Penha, Jaraguá, SJB, Guabiruba, Corupá, Ilhota, Porto Belo) no
//     legislador.com.br. 1 GET/câmara + dedup por hash = leve; 4×/dia basta
//     (proposição nova é evento diário). :25 na grade /5, fora dos ticks
//     pesados (juiz 5,35 · dom */10); o jr:camara-ingest de :25 é horário e
//     ambos são HTTP-leves (hosts diferentes).
Schedule::command('jr:legislador-ingest')
    ->cron('25 2,8,14,20 * * *')
    ->withoutOverlapping(600);

// (4) ITAPEMA elegis2 (02/07) — forward com early-stop (--parar-vistos); item
//     novo custa 2 GETs + PDF (ementa via PyMuPDF). 4×/dia, offset :45.
Schedule::command('jr:itapema-ingest')
    ->cron('45 2,8,14,20 * * *')
    ->withoutOverlapping(600);

// (5) FIRECRAWL (Fase 2 — SoftCâmaras/LEGISOFT, 30 cidades gated) — BLOQUEADO
//     02/07: sem FIRECRAWL_API_KEY (falta o Lorran contratar/colar a chave).
//     Quando chegar: .env + JRCAM_FIRECRAWL_ATIVO=true, PoC --poc --dry,
//     validar parsers e DESCOMENTAR abaixo. Cadência gentil de propósito:
//     1×/dia (1 request/câmara/dia — crédito do serviço + educação).
// Schedule::command('jr:firecrawl-ingest')
//     ->cron('30 7 * * *')
//     ->withoutOverlapping(3600);

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

// (3) SEGUNDA PASSADA vespertina — o DOE do DIA costuma sair depois das 08:45
// (mesmo padrão do TCE em 01/07); a repetição é barata (dedup por hash).
// NB (02/07): o app roda em UTC ⇒ '45 16' dispara às 13:45 LOCAIS. Mantida de
// propósito (pega edição que sai cedo); a passada que garante a edição do DIA
// é a NOTURNA em UTC abaixo (18:45–20:00 locais).
Schedule::command('jr:mpsc-ingest --dias=3')
    ->cron('45 16 * * 1-6')
    ->withoutOverlapping(600);

Schedule::command('jr:mpsc-score')
    ->cron('55 16 * * 1-6')
    ->withoutOverlapping(600);

// (4) PASSADA NOTURNA em UTC (02/07/2026, fix A4) — a "vespertina" acima nunca
// rodou na hora pretendida (timezone UTC desloca a grade −3h). Estes horários
// UTC = 19:50/20:00 locais pro MPSC, cobrindo a edição do dia que sai à noite.
// Aditivo e idempotente (dedup por hash); grade /5 do timer.
Schedule::command('jr:mpsc-ingest --dias=3')
    ->cron('50 22 * * 1-6')
    ->withoutOverlapping(600);

Schedule::command('jr:mpsc-score')
    ->cron('0 23 * * 1-6')
    ->withoutOverlapping(600);

// ── RADAR CÍVICO Fase 5 — TCE-SC (DOTC-e PDF diário) — decisões/julgamentos ──
// ADITIVO/ISOLADO. DOTC-e sai Seg-Sex; forward varre dias úteis (404 no fds).
// PDF datado direto (o índice tem shield anti-bot). Parsing PyMuPDF. Grade /5.
// Duas passadas: a edição do DIA costuma sair DEPOIS das 09:50 (01/07 ela só
// existia à noite — a manhã pegava sempre a de ontem). A vespertina cobre a
// edição do próprio dia; --dias=3 + dedup por hash fazem a repetição ser barata.
Schedule::command('jr:tce-ingest --dias=3')
    ->cron('50 9 * * 1-6')
    ->withoutOverlapping(600);

Schedule::command('jr:tce-score')
    ->cron('0 10 * * 1-6')
    ->withoutOverlapping(600);

// NB (02/07): '50 16' UTC = 13:50 local — mantida (barata, dedup por hash);
// a garantia da edição do dia é a passada noturna UTC abaixo (fix A4).
Schedule::command('jr:tce-ingest --dias=3')
    ->cron('50 16 * * 1-6')
    ->withoutOverlapping(600);

Schedule::command('jr:tce-score')
    ->cron('0 17 * * 1-6')
    ->withoutOverlapping(600);

// PASSADA NOTURNA em UTC (02/07/2026, fix A4): 21:45/21:55 UTC = 18:45/18:55
// locais — horário em que a edição do DIA do DOTC-e já existe (01/07 ela só
// apareceu à noite). Aditivo, idempotente por hash, grade /5.
Schedule::command('jr:tce-ingest --dias=3')
    ->cron('45 21 * * 1-6')
    ->withoutOverlapping(600);

Schedule::command('jr:tce-score')
    ->cron('55 21 * * 1-6')
    ->withoutOverlapping(600);

// ── RADAR CÍVICO BLOCO 3 (02/07) — NOTÍCIA institucional de prefeitura ──
// ADITIVO/ISOLADO. 14 prefeituras de interesse (config/prefeitura.php),
// forward-first (~15 mais recentes por fonte), dedup por hash(url), data
// sanitizada (Recencia). Release = versão oficial; o scorer (lente 🟢) marca
// e o rascunho sinaliza. Grade /5 do timer; educado (1 req/s/host).
Schedule::command('jr:prefeitura-ingest')
    ->cron('20 6,12,18,23 * * *')
    ->withoutOverlapping(1200);

Schedule::command('jr:prefeitura-score --limit=40')
    ->cron('40 6,12,18,23 * * *')
    ->withoutOverlapping(1800);

// ── MESA DE PAUTA Fase 4 — ALERTA das pautas quentes no WHATSAPP ──
// (BLOCO 2, 02/07: saiu do Telegram — grupo interno via instância de alerta
// JRLINK_ALERT_ZAPI_*.) ADITIVO/ISOLADO. Digest por ciclo (anti-flood) das
// pautas quentes E novas (data_pub ≤7d, BLOCO 1); dedup em jr_civico_alertas.
// FAIL-CLOSED: sem instância/grupo no .env, NÃO envia (só loga). Ao religar
// fonte, rodar `jrcivico:alertar --seed` antes (baseline anti-flood).
Schedule::command('jrcivico:alertar')
    ->everyFiveMinutes()
    ->withoutOverlapping(600);

// ── WATCHDOG v1 — vigia READ-ONLY (02/07/2026) ──
// Regenera public/health.html (página PASSIVA, sem push — Lorran NÃO quer
// notificação de saúde): lint de cron, frescor feed/whatsapp/instagram, captura
// WhatsApp medida NA FONTE (raw do webhook + jr_pauta_capturas), serviços,
// Radar Cívico (4 fontes + fila de scoring DOM) e sessão claude-cli (ping).
// */15 na grade /5 do timer; offset :00 não conflita (é leve e read-only).
Schedule::command('jrlink:watchdog')
    ->everyFifteenMinutes()
    ->withoutOverlapping(600);
