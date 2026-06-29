<?php

/**
 * Conector MPSC (Diário Oficial Eletrônico) — Radar Cívico FASE 4. ADITIVO e
 * ISOLADO: config/tabela/comandos próprios. NÃO toca DOM/câmara/juiz/captura.
 *
 * O DOE-MPSC é um PDF diário (Seg-Sex) em URL datada. Extraímos os EXTRATOS DE
 * INSTAURAÇÃO de procedimentos das Promotorias (inquérito civil / notícia de fato
 * / procedimento preparatório) — "o MP abre investigação sobre X". source=mpsc.
 *
 * Parsing do PDF: PyMuPDF no venv dedicado scripts/pdf-venv (sem dep de sistema),
 * via scripts/mpsc_extract.py. Faro = Sonnet (dual-lens, lente MPSC).
 */
return [
    // URL do PDF por data: .../do_mpsc_AAAA-MM-DD (Seg-Sex; sem edição fim de semana).
    'url_base' => env('JRMPSC_URL', 'https://www.mpsc.mp.br/documents/d/guest/do_mpsc_'),

    'user_agent' => env('JRMPSC_UA', 'JornalRazaoBot/0.1 (+contato lorranfbjr@gmail.com; pesquisa jornalistica DOE-MPSC)'),

    'pausa_seg' => (float) env('JRMPSC_PAUSA', 1.0),

    // Quantos dias ÚTEIS pra trás varrer no forward (default).
    'dias_uteis' => (int) env('JRMPSC_DIAS', 5),

    // venv + script de extração do PDF.
    'python' => base_path('scripts/pdf-venv/bin/python'),
    'extrator' => base_path('scripts/mpsc_extract.py'),

    // ── faro (scoring), separado do juiz/Opus; Sonnet trocável ──
    'scoring' => [
        'modelo' => env('JRMPSC_MODELO_SCORING', 'claude-sonnet-4-6'),
        'lote' => (int) env('JRMPSC_LOTE', 6),
        'cap_chamadas' => (int) env('JRMPSC_CAP', 300),
    ],
];
