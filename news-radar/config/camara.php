<?php

/**
 * Conector SAPL (câmaras) — Radar Cívico, FASE 2. ADITIVO e ISOLADO: config
 * próprio, tabela própria (jr_camara_proposicoes), comandos próprios. NÃO toca
 * DOM, juiz, captura nem dispatcher. Reaproveita o FARO (Sonnet, dual-lens 🔴/🟢)
 * com lente CÂMARA. source=camara.
 *
 * SAPL (Interlegis, open-source) expõe REST: /api/materia/materialegislativa/
 * (paginação DRF, total_entries), /api/materia/tipomaterialegislativa/ (id→sigla/
 * descrição — VARIA por instância, por isso resolvemos por DESCRIÇÃO em runtime)
 * e /api/base/autor/ (id→nome). Acesso público, sem auth.
 *
 * RECON 29/06/2026 (probe sapl.<cidade>.sc.leg.br): das 32 prioritárias, só 5
 * respondem com API SAPL viva. As demais são NXDOMAIN ou portal próprio (Floripa
 * = CMF custom, Joinville = LEGISOFT) — anotadas pra raspador dedicado, NÃO aqui.
 */
return [
    'user_agent' => env('JRCAM_UA', 'JornalRazaoBot/0.1 (+contato lorranfbjr@gmail.com; pesquisa jornalistica proposicoes camaras SC)'),

    // Pausa entre requisições (politeness).
    'pausa_seg' => (float) env('JRCAM_PAUSA', 0.6),

    // Câmaras com SAPL vivo (recon RE-CONFIRMADO 29/06/2026, probe ?ano=2026/2025
    // + última proposição via o=-data_apresentacao). 'recencia' = última matéria
    // de fato na API. SÓ as 'vivo'=true alimentam o forward (jr:camara-ingest pula
    // as mortas por padrão; --incluir-mortas força). As mortas ficam aqui só como
    // documentação de cobertura — NÃO são pauta atual e o radar as filtra por data.
    //
    // VIVAS (2): São Bento do Sul (2026), Rio do Sul (2026).
    // MORTAS (4): SAPL existe mas parou — Canoinhas (2024), Imbuia (2023),
    //   Tijucas (2022), São José (2020). Onde publicam hoje (probe VPS 29/06):
    //   Imbuia AINDA roda SAPL/Interlegis (só não alimenta desde jun/2023);
    //   Tijucas tem portal próprio (openresty, sem Interlegis);
    //   Canoinhas e São José bloqueiam probe (503 LiteSpeed) — recon manual pendente.
    // Demais ~31 prioritárias = AUSENTE (sem SAPL no host padrão) — ver scoping.
    'camaras' => [
        ['cidade' => 'São Bento do Sul', 'host' => 'sapl.saobentodosul.sc.leg.br',   'recencia' => '2026-06-29', 'vivo' => true],
        ['cidade' => 'Rio do Sul',       'host' => 'sapl.camarariodosul.sc.gov.br',   'recencia' => '2026-06-26', 'vivo' => true],
        ['cidade' => 'Canoinhas',        'host' => 'sapl.canoinhas.sc.leg.br',        'recencia' => '2024-07-31', 'vivo' => false],
        ['cidade' => 'Imbuia',           'host' => 'sapl.imbuia.sc.leg.br',           'recencia' => '2023-06-29', 'vivo' => false],
        ['cidade' => 'Tijucas',          'host' => 'sapl.tijucas.sc.leg.br',          'recencia' => '2022-11-22', 'vivo' => false],
        ['cidade' => 'São José',         'host' => 'sapl.saojose.sc.leg.br',          'recencia' => '2020-09-16', 'vivo' => false],
    ],

    // Tipos de matéria RELEVANTES (lei-making): selecionados por DESCRIÇÃO
    // normalizada (sigla/id variam por instância). Excluímos ruído: indicação,
    // moção, requerimento, emenda, certificado, parecer, prestação de contas.
    'tipos_relevantes' => [
        'projeto de lei',
        'decreto legislativo',
        'projeto de resolucao',
        'emenda a lei organica',
    ],

    // Teto de páginas (10 itens/página) por câmara numa varredura.
    'max_paginas' => (int) env('JRCAM_MAX_PAGINAS', 60),

    // FORWARD: quantos anos varrer recente-primeiro (ano corrente + N-1 anteriores).
    // 2 = ano corrente + anterior — pega proposição de fim do ano passado ainda
    // viva como pauta, sem cair no histórico inteiro (isso é o --backfill).
    'janela_anos' => (int) env('JRCAM_JANELA_ANOS', 2),

    // RADAR: só mostra proposição com data_pub nos últimos N meses — rebaixa feeds
    // mortos (Canoinhas 2024, Tijucas 2022, São José 2020) que não são pauta atual.
    'radar_meses' => (int) env('JRCAM_RADAR_MESES', 18),

    // ── faro (scoring), separado do juiz/Opus; Sonnet trocável por env ──
    'scoring' => [
        'modelo' => env('JRCAM_MODELO_SCORING', 'claude-sonnet-4-6'),
        'lote' => (int) env('JRCAM_LOTE', 6),
        'cap_chamadas' => (int) env('JRCAM_CAP', 500),
    ],
];
