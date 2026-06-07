<?php

/**
 * Regras do classificador da camada de extração de links (jrlink:extract).
 *
 * Edite as listas abaixo para estender o classificador — tudo é casado contra o
 * HOST RESOLVIDO (host final, já seguido o redirect do encurtador), nunca contra
 * o fonte_tipo da captura. Um host casa um domínio D se host == D ou host termina
 * em ".D" (ex.: "g1.globo.com" casa o domínio "globo.com").
 *
 * Ordem de decisão da categoria: proprio -> social -> primaria -> concorrente -> outro.
 */
return [

    // 🟦 Matéria nossa já publicada — NÃO reescreve; serve de dedup ("já saiu").
    'proprio' => [
        'jornalrazao.com',
    ],

    // 📱 Redes sociais — título pode carregar conteúdo (legenda), corpo é muro de login.
    'social' => [
        'whatsapp.com',
        'chat.whatsapp.com',
        'instagram.com',
        'facebook.com',
        'fb.com',
        'fb.watch',
        'x.com',
        'twitter.com',
        'youtube.com',
        'youtu.be',
        'tiktok.com',
        't.me',
        'telegram.me',
    ],

    // ✅ Fonte PRIMÁRIA / oficial — pode reescrever.
    // Sufixos: host termina exatamente nisso.
    'gov_suffixes' => [
        '.gov.br',
        '.leg.br',
        '.jus.br',
        '.mp.br',
    ],
    // Sinais: substring no host (órgãos oficiais sem .gov.br no domínio).
    'gov_signals' => [
        'prefeitura',
        'camara',
        'pmsc',
        'policiamilitar',
        'policiacivil',
        'prf',
        'bombeiros',
        'cbmsc',
        'tjsc',
        'mpsc',
        'emasa',
    ],

    // 🚫 CONCORRENTE — site de notícia. RADAR, não reescreve (seria plágio).
    'concorrente' => [
        'globo.com',
        'g1.globo.com',
        'glo.bo',
        'gshow.globo.com',
        'oglobo.globo.com',
        'nsctotal.com.br',
        'sjagora.com.br',
        'omunicipio.com.br',
        'ndmais.com.br',
        'ndtv.com.br',
        'clicrbs.com.br',
        'diariocatarinense.com.br',
        'horadesc.com.br',
        'ocp.news',
        'jornaldetijucas.com.br',
        'sccomvoce.com.br',
        'panoramanoticiassc.com.br',
        'carneironews.com.br',
        'munira.com.br',
        'folha.uol.com.br',
        'uol.com.br',
        'metropoles.com',
        'cnnbrasil.com.br',
        'r7.com',
        'terra.com.br',
        'otempo.com.br',
        'band.com.br',
        'cartacapital.com.br',
    ],

    // Gate de qualidade: marcadores de muro de login / boilerplate (casados em minúsculo).
    // Se o corpo (sem frontmatter/cabeçalho Jina) tem isso, NÃO é "ok".
    'walls' => [
        'whatsapp group invite',
        'stay connected with voice and video',
        'log into instagram',
        'mobile number, username or email',
        'looks like you don',
        "don't have whatsapp",
        'join chat',
        'forgot password',
        'forgot account',
        'create new account',
        'log in',
        'log into',
        '登录',
        '# facebook',
        '# instagram',
    ],

    // Títulos genéricos = sem conteúdo real (não contam como sinal).
    'generic_titles' => [
        'facebook', 'instagram', 'whatsapp', 'whatsapp.com',
        'x', 'twitter', 'youtube', 'tiktok', 'telegram',
        'log in', 'login', 'entrar', 'sign in',
    ],

    // Mínimo de chars de corpo real (sem boilerplate) para um não-social ser "ok".
    'min_corpo_ok' => 300,

    // Parâmetros de tracking removidos na normalização da URL (dedup).
    // utm_* é tratado por prefixo separadamente.
    'tracking_params' => [
        'igsh', 'igshid', 'fbclid', 'gclid', 'mode', 'utm_source', 'utm_medium',
        'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 's', 'ref', 'rdid',
        'share_url',
    ],
];
