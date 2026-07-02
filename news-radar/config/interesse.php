<?php

/**
 * CIDADES DE INTERESSE do Jornal Razão — ranking de EXIBIÇÃO do Radar Cívico e
 * do Radar de Oportunidades. NÃO re-scora nada: só pesa a ordenação/filtro.
 * score_pauta no BANCO fica intacto (insumo de jrcivico:alertar, Mesa, watchdog).
 *
 * tier1 = núcleo de cobertura + cidades com presença comercial ativa/recente (90d);
 * tier2 = vizinhas/satélites das tier1 (mapa de vizinhança validado 02/07/2026).
 *
 * ⚠️ Listas com GRAFIA OFICIAL (DomGeografia::municipios()) — o lookup PHP
 * normaliza acento/caixa, mas o whereIn SQL usa a grafia oficial byte-a-byte.
 * (Validação 02/07: "Guatambu" da lista original corrigido p/ "Guatambú".)
 */
return [
    // ── tiers (env CSV sobrepõe; default = decisão editorial de 02/07/2026) ──
    'tier1' => array_values(array_filter(array_map('trim', explode(',',
        (string) env('JR_INTERESSE_TIER1',
            'Tijucas,Canelinha,São João Batista,Nova Trento,Porto Belo,Bombinhas,'
            . 'Itapema,Florianópolis,Blumenau,Chapecó,Criciúma,Jaraguá do Sul,'
            . 'Navegantes,Palhoça,Governador Celso Ramos,Balneário Camboriú,Biguaçu,'
            . 'Brusque,Itajaí,Lages,Indaial,São José,Joinville,Guabiruba,Balneário Piçarras'
        ))))),
    'tier2' => array_values(array_filter(array_map('trim', explode(',',
        (string) env('JR_INTERESSE_TIER2',
            'Penha,Ilhota,Luiz Alves,Camboriú,Major Gercino,Antônio Carlos,'
            . 'Leoberto Leal,Botuverá,Gaspar,Pomerode,Timbó,Guaramirim,Schroeder,'
            . 'Corupá,Massaranduba,Santo Amaro da Imperatriz,Paulo Lopes,Xaxim,'
            . 'Coronel Freitas,Cordilheira Alta,Guatambú,Içara,Forquilhinha,'
            . 'Cocal do Sul,Nova Veneza,Siderópolis,Otacílio Costa,Correia Pinto,'
            . 'Araquari,São Francisco do Sul'
        ))))),

    // ── pesos no score de EXIBIÇÃO (não tocam score_pauta no banco) ──
    'pesos' => [
        'tier1' => (int) env('JR_INTERESSE_BONUS_T1', 12),
        'tier2' => (int) env('JR_INTERESSE_BONUS_T2', 6),
    ],

    // ── anti-genérico: objeto vago CAPA o score de exibição ──
    'anti_generico' => [
        'cap'      => (int) env('JR_VAGO_CAP', 55),   // nunca passa de 55 (fica fora do "hi"/"mid")
        'min_len'  => (int) env('JR_VAGO_MINLEN', 25), // objeto_limpo mais curto que isso = vago
    ],

    // ── decay de recência (cara do gol = últimas 48h) ──
    'decay' => [
        'carencia_dias' => (int) env('JR_DECAY_CARENCIA', 1), // data_pub >= hoje-1 → sem decay + seção topo
        'por_dia'       => (int) env('JR_DECAY_DIA', 4),      // -4 pontos por dia além da carência
        'max'           => (int) env('JR_DECAY_MAX', 24),     // teto do desconto
    ],

    // ── recência server-side (missão F): quota de frescos no JSON ──
    'frescos_dias'  => (int) env('JR_FRESCOS_DIAS', 2),   // últimos 2 dias SEMPRE entram
    'frescos_limit' => (int) env('JR_FRESCOS_LIMIT', 150),
    'interesse_limit' => (int) env('JR_INTERESSE_LIMIT', 150),
];
