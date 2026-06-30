<?php

/**
 * SEGUNDO OLHAR — 2º faro com OpenAI (gpt-4o-mini), CEGO e independente do Sonnet.
 *
 * Estratégia: o GPT NÃO vê o que o Sonnet decidiu — dá o veredito dele do zero,
 * só nos CANDIDATOS A PAUTA (score do Sonnet >= min_score). Onde os dois modelos
 * concordam = confiança alta; onde divergem (>= limiar OU desacordo de classe) =
 * flag "revisar" pra olho humano na Mesa. Diversidade de julgamento, não economia.
 *
 * Fail-closed: sem OPENAI_API_KEY real (sk-noop/test/placeholder = inerte), o
 * segundo olhar não roda. Isolado: tabela própria de colunas (*_2), NÃO altera o
 * score_pauta do Sonnet nem o radar. Custo (token) é baixo no recorte de pauta.
 */
return [
    'modelo' => env('SEGUNDO_OLHAR_MODELO', 'gpt-4o-mini'),

    // só opina onde o Sonnet já viu pauta (score_pauta >= min_score).
    'min_score' => (int) env('SEGUNDO_OLHAR_MIN', 50),

    // divergência: |score_sonnet - score_gpt| >= limiar OU o GPT diz "não é pauta".
    'limiar_divergencia' => (int) env('SEGUNDO_OLHAR_LIMIAR', 25),

    // itens por chamada (lote) e teto de chamadas por execução (politeness/custo).
    'lote' => (int) env('SEGUNDO_OLHAR_LOTE', 8),
    'cap_chamadas' => (int) env('SEGUNDO_OLHAR_CAP', 600),
];
