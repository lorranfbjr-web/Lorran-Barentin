<?php

/**
 * Conector TCE-SC (DOTC-e, Diário de Contas eletrônico) — Radar Cívico FASE 5.
 * ADITIVO e ISOLADO. NÃO toca DOM/câmara/MPSC/juiz/captura.
 *
 * O DOTC-e é um PDF diário (Seg-Sex) em URL datada (dotc-e<AAAA-MM-DD>.pdf).
 * Extraímos os PROCESSOS/DECISÕES do Tribunal: representações, relatórios de
 * inspeção/auditoria, denúncias, prestações de contas, decisões singulares —
 * onde moram multa, conta rejeitada, irregularidade confirmada. source=tce.
 *
 * (A "API de dados abertos" do TCE só traz metadado — por isso parseamos o PDF.)
 * Parsing via PyMuPDF (venv dedicado). Faro = Sonnet (dual-lens, lente TCE).
 */
return [
    'url_base' => env('JRTCE_URL', 'https://consulta.tce.sc.gov.br/Diario/dotc-e'),

    'user_agent' => env('JRTCE_UA', 'JornalRazaoBot/0.1 (+contato lorranfbjr@gmail.com; pesquisa jornalistica DOTC-e TCE-SC)'),

    'pausa_seg' => (float) env('JRTCE_PAUSA', 1.0),

    'dias_uteis' => (int) env('JRTCE_DIAS', 5),

    'python' => base_path('scripts/pdf-venv/bin/python'),
    'extrator' => base_path('scripts/tce_extract.py'),

    'scoring' => [
        'modelo' => env('JRTCE_MODELO_SCORING', 'claude-sonnet-4-6'),
        'lote' => (int) env('JRTCE_LOTE', 6),
        'cap_chamadas' => (int) env('JRTCE_CAP', 300),
    ],
];
