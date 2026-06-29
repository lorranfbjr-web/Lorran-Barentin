<?php

/**
 * Conector TJSC (DJe — Diário da Justiça eletrônico) — Radar Cívico FASE 6.
 * ⚠️ EXPERIMENTAL / DOCUMENTADO. ADITIVO e ISOLADO.
 *
 * A REST do DJe funciona e o PDF parseia, MAS o sinal/ruído é ruim demais: o DJe
 * publica INTIMAÇÕES e RELAÇÕES de processos (referência + nº), NÃO o TEXTO das
 * decisões — a substância mora no eproc, atrás do número. O filtro agressivo
 * (ente público + substância) rende ~0,4 itens/dia e mesmo esses são ruído
 * (intimação de mandado de segurança sobre reajuste, etc.). Por isso entregamos
 * uma SONDA read-only (jr:tjsc-probe) + esta documentação, SEM ingestão em
 * produção (não poluir o radar). Ver goals/SCOPING-fase6-tjsc.md.
 *
 *   REST: busca.tjsc.jus.br/dje-consulta/rest/diario/caderno?edicao=N&cdCaderno=N
 *   Cadernos vistos: 4 = Matérias Administrativas; 6 = Matérias Jurídicas (PDF).
 */
return [
    'url_base' => env('JRTJSC_URL', 'https://busca.tjsc.jus.br/dje-consulta/rest/diario/caderno'),

    'user_agent' => env('JRTJSC_UA', 'JornalRazaoBot/0.1 (+contato lorranfbjr@gmail.com; pesquisa jornalistica DJe-TJSC)'),

    'pausa_seg' => (float) env('JRTJSC_PAUSA', 1.2),

    // Cadernos com matéria jurídica (6) e administrativa (4).
    'cadernos' => [4, 6],

    'python' => base_path('scripts/pdf-venv/bin/python'),
    'extrator' => base_path('scripts/tjsc_extract.py'),
];
