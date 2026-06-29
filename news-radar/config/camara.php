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

    // Câmaras com SAPL vivo (recon confirmado). 'recencia' = última proposição
    // vista no probe — LIVE alimenta o forward; as stale entram como histórico
    // (1ª-mão de leis passadas; o forward nelas vira no-op, é esperado).
    'camaras' => [
        ['cidade' => 'São Bento do Sul', 'host' => 'sapl.saobentodosul.sc.leg.br',   'recencia' => '2026-06-29', 'vivo' => true],
        ['cidade' => 'Rio do Sul',       'host' => 'sapl.camarariodosul.sc.gov.br',   'recencia' => '2026-06-26', 'vivo' => true],
        ['cidade' => 'Canoinhas',        'host' => 'sapl.canoinhas.sc.leg.br',        'recencia' => '2024-07-16', 'vivo' => false],
        ['cidade' => 'Tijucas',          'host' => 'sapl.tijucas.sc.leg.br',          'recencia' => '2022-11-01', 'vivo' => false],
        ['cidade' => 'São José',         'host' => 'sapl.saojose.sc.leg.br',          'recencia' => '2020-03-01', 'vivo' => false],
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

    // ── faro (scoring), separado do juiz/Opus; Sonnet trocável por env ──
    'scoring' => [
        'modelo' => env('JRCAM_MODELO_SCORING', 'claude-sonnet-4-6'),
        'lote' => (int) env('JRCAM_LOTE', 6),
        'cap_chamadas' => (int) env('JRCAM_CAP', 500),
    ],
];
