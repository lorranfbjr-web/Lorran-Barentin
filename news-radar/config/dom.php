<?php

/**
 * Conector DOM/SC + Radar de Oportunidades (PoC). Config ISOLADO — não toca em
 * jrlink.php nem no juiz. Modelo de scoring é SEPARADO do juiz e TROCÁVEL por
 * env (pra testar GPT/outro depois). Custo NÃO é gate (plano Max).
 */
return [
    // Busca pública do Diário Oficial dos Municípios de SC (FECAM/CIGA).
    'base_url' => env('JRDOM_BASE_URL', 'https://diariomunicipal.sc.gov.br'),

    // User-Agent identificável (ser educado com a fonte pública).
    'user_agent' => env('JRDOM_UA', 'JornalRazaoBot/0.1 (+contato lorranfbjr@gmail.com; pesquisa jornalistica dados publicos DOM/SC)'),

    // Pausa entre requisições de listagem (segundos) — rate-limit, sem martelar.
    'pausa_seg' => (float) env('JRDOM_PAUSA', 0.8),

    // Categorias de "compras" varridas por padrão (uma busca por categoria).
    // Nomes EXATOS do select de categoria do DOM/SC.
    'categorias' => [
        'Dispensas',
        'Licitações',
        'Contratos',
        'Ata de registro de preços',
    ],

    // Janela padrão (dias) e teto de páginas por categoria (politeness + bound da
    // PoC; 10 atos/página). Listagem é ordenada por recência.
    'dias' => (int) env('JRDOM_DIAS', 14),
    'max_paginas' => (int) env('JRDOM_MAX_PAGINAS', 40),

    // ── Crawler RETROATIVO (OBJ1) — varre o histórico pra trás aos poucos, em
    // background, sem competir com o forward. Cursor persistido em jr_dom_estado.
    'retro' => [
        // Profundidade-alvo: até quantos dias atrás encher (parar quando chega lá).
        'alvo_dias' => (int) env('JRDOM_RETRO_ALVO', 60),
        // Tamanho do passo: cada execução varre uma janela deste tamanho e recua.
        'chunk_dias' => (int) env('JRDOM_RETRO_CHUNK', 3),
        // Teto de páginas/categoria por chunk (a janela curta costuma drenar antes).
        'max_paginas' => (int) env('JRDOM_RETRO_MAX_PAGINAS', 60),
    ],

    // ── Radar de Oportunidades (scoring) ──
    'scoring' => [
        // SEPARADO do juiz (Opus). Sonnet por padrão; trocável por env.
        'modelo' => env('JRDOM_MODELO_SCORING', 'claude-sonnet-4-6'),
        // Atos por chamada (lote no mesmo prompt) — menos overhead de sessão.
        'lote' => (int) env('JRDOM_LOTE', 6),
        // Hard cap de chamadas por execução (backstop).
        'cap_chamadas' => (int) env('JRDOM_CAP', 500),
    ],
];
