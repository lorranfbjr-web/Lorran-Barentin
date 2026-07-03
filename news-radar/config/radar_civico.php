<?php

/**
 * MESA DE PAUTA — config dos ALERTAS do Radar Cívico (Fase 4).
 *
 * Limiares 100% editáveis aqui. Os ALVOS de mensagem vêm do .env e NUNCA são
 * inventados em código: sem TELEGRAM_BOT_TOKEN + RADAR_CIVICO_ALERT_CHAT_ID o
 * comando `jrcivico:alertar` roda em modo seco (loga o que MANDARIA, não envia).
 *
 * Reuso do bot do Gerador JR: aponte TELEGRAM_BOT_TOKEN pro token do bot já
 * existente; o chat de destino dos alertas é uma DECISÃO do Lorran (DM dele ou
 * grupo dedicado) — por isso fica num env próprio, separado do fluxo de publicar.
 */
return [
    'alertas' => [
        // pauta entra no alerta se QUALQUER gatilho bater:
        'score_min' => (int) env('RADAR_CIVICO_SCORE_MIN', 80),              // quente "geral"
        'cidades_prioritarias' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('RADAR_CIVICO_CIDADES', 'Tijucas,Canelinha,São João Batista,Nova Trento,Porto Belo,Bombinhas'))
        ))),
        'score_cidade' => (int) env('RADAR_CIVICO_SCORE_CIDADE', 60),        // limiar nas cidades prioritárias
        'fontes_chave' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('RADAR_CIVICO_FONTES_CHAVE', 'mpsc,tce'))
        ))),
        'score_fonte_chave' => (int) env('RADAR_CIVICO_SCORE_FONTE', 60),    // limiar nas fontes-chave

        // BLOCO 1 (02/07): janela por DATA DE PUBLICAÇÃO — só alerta o que foi
        // publicado nos últimos N dias (e nunca data futura/suspeita). Substitui
        // a janela antiga por scored_at (RADAR_CIVICO_JANELA_H), que deixava
        // backfill de anos atrás alertar como se fosse quente.
        'alert_dias' => (int) env('RADAR_CIVICO_ALERT_DIAS', 7),
        // teto de linhas por mensagem (resto vira "… e mais X")
        'max_por_ciclo' => (int) env('RADAR_CIVICO_MAX_CICLO', 12),
    ],

    /*
     * BLOCO 2 (02/07): o radar cívico SAIU do Telegram — o bot do Telegram
     * (@jornalrazaopubli_bot) voltou a ser 100% do Gerador v3 → aprovação FB.
     * Alertas e rascunhos do radar agora vão pro WHATSAPP via instância de
     * ALERTA (JRLINK_ALERT_ZAPI_*, a "3…" — NUNCA a 276 de captura nem a 884
     * do disparador). Dois canais, config-driven:
     *   sugestoes → ALERTA de pauta quente do radar (grupo SUGESTÕES DE PAUTA)
     *   rascunhos → rascunho pronto da Mesa      (grupo RASCUNHOS)
     * Enquanto o 2º grupo não existe, sugestoes cai no RASCUNHOS (fallback).
     * Quando o Lorran criar o grupo de sugestões: setar RADAR_CIVICO_SUGESTOES_GROUP.
     */
    'canais' => [
        'sugestoes' => env('RADAR_CIVICO_SUGESTOES_GROUP', env('JRLINK_RASCUNHOS_GROUP', '')),
        'rascunhos' => env('RADAR_CIVICO_RASCUNHO_GROUP', env('JRLINK_RASCUNHOS_GROUP', '')),
    ],

    // PARQUEADO (02/07): ninguém do radar lê mais este bloco — mantido só pra
    // referência histórica do TelegramCivico.php (também parqueado).
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN', ''),            // bot do Gerador JR (NÃO usar no radar)
        'chat_id' => env('RADAR_CIVICO_ALERT_CHAT_ID', ''),  // idem
    ],

    // base pública pro "link pro card" (#ato-<source>-<id> no Radar Cívico)
    'base_url' => env('RADAR_CIVICO_BASE_URL', env('APP_URL', '')),

    /*
     * RASCUNHO (Fase 5) — botão "✍️ criar rascunho" na Mesa. Gera um draft JR do
     * ato (LLM, Opus) e ENTREGA SÓ no WhatsApp do Lorran (DM). NÃO publica.
     *
     * ALVO FAIL-CLOSED: sem `phone` (número pessoal do Lorran) o draft é gerado e
     * mostrado na própria Mesa, mas NÃO é enviado a ninguém — nunca cai em grupo,
     * nunca toca o disparador 884. Reusa a instância Z-API própria do Radar (a
     * mesma do alerta/notificação), NÃO o n8n. Modelo = mesmo do pipeline (Opus).
     */
    'rascunho' => [
        'phone' => env('RADAR_CIVICO_RASCUNHO_PHONE', ''),   // SÓ o número do Lorran (DM)
        'instance' => env('JRLINK_ALERT_ZAPI_INSTANCE', ''),
        'token' => env('JRLINK_ALERT_ZAPI_TOKEN', ''),
        'client_token' => env('JRLINK_ALERT_ZAPI_CLIENT_TOKEN', ''),
        'modelo' => env('RADAR_CIVICO_RASCUNHO_MODELO', 'claude-opus-4-8'),
    ],
];
