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

    /*
    |---------------------------------------------------------------------------
    | QUENTE / FRIO — dois eixos, por palavra-chave (zero custo, edite à vontade)
    |---------------------------------------------------------------------------
    | Região é detectada pelo CONTEÚDO (título + markdown), NUNCA por fonte_cidade
    | (que é majoritariamente nulo). Pesos calibrados pelo que validamos no GA:
    | gancho conquista-superação e indignação no topo; tema leve (feel-good /
    | economia / bicho / meio-ambiente) abaixo; cidade da região é piso/bônus.
    */

    // Sinais de região na cobertura — casados em minúsculo no título+markdown.
    'regiao' => [
        'cidades' => [
            'balneário camboriú', 'balneario camboriu', 'camboriú', 'camboriu',
            'itajaí', 'itajai', 'são josé', 'sao jose', 'florianópolis', 'florianopolis',
            'tijucas', 'itapema', 'navegantes', 'penha', 'piçarras', 'picarras',
            'barra velha', 'brusque', 'blumenau', 'joinville', 'são joão batista',
            'canelinha', 'porto belo', 'bombinhas', 'governador celso ramos',
            'biguaçu', 'biguacu', 'palhoça', 'palhoca', 'lages', 'são joaquim',
            'sao joaquim', 'joaçaba', 'joacaba', 'nova trento', 'major gercino',
        ],
        // Sinais de Santa Catarina (estado) — bônus menor que cidade.
        'estado' => [
            'santa catarina', ' sc ', ' sc.', ' sc,', '/sc', 'sc-', '-sc',
            'em sc', 'de sc', 'no estado', 'litoral catarinense', 'vale do itajaí',
            'grande florianópolis',
        ],
    ],

    // Temas/ganchos com peso (somados ao score).
    'temas' => [
        // Topo: conquista-superação + indignação (o que mais performou no GA).
        'gancho_top' => [
            'peso' => 5,
            'termos' => [
                'recorde', 'inédito', 'inedito', 'primeiro', 'pioneiro', 'conquista',
                'conquistou', 'superação', 'superacao', 'superou', 'venceu', 'medalha',
                'campeão', 'campea', 'campeã', 'prêmio', 'premio', 'premiado', 'homenagem',
                'revolta', 'indignação', 'indignacao', 'absurdo', 'flagrante', 'denúncia',
                'denuncia', 'escândalo', 'escandalo', 'abandonado', 'descaso', 'golpe',
                'fraude', 'preso', 'prisão', 'prisao', 'morto', 'morre', 'morreu',
                'acidente', 'resgate', 'resgatado', 'desaparecido',
            ],
        ],
        // Tema leve: feel-good / economia / bicho / meio-ambiente.
        'tema_leve' => [
            'peso' => 3,
            'termos' => [
                'cachorro', 'cão', 'cao', 'gato', 'animal', 'adoção', 'adocao', 'pet',
                'meio ambiente', 'natureza', 'praia', 'sustentável', 'sustentavel',
                'economia', 'emprego', 'vaga', 'renda', 'preço', 'preco', 'custo',
                'festival', 'festa', 'show', 'solidári', 'doação', 'doacao',
                'voluntári', 'criança', 'crianca', 'idoso', 'saúde', 'saude',
            ],
        ],
    ],

    // Réguas DIFERENTES por eixo. Score = base + bônus região + pesos de tema.
    'reguas' => [
        // EIXO 1 — primária (gov/oficial): "vira pauta direto".
        // Release de gov/PMSC de SC já é regional pela fonte -> regional é PESO, não trava.
        'primaria' => [
            'base' => 2,
            'peso_regiao_cidade' => 3,
            'peso_regiao_estado' => 1,
            'exige_regiao' => false,
            'exige_gancho' => false,
            'corte_quente' => 5, // frouxo: gancho OU tema OU cidade já esquenta
        ],
        // EIXO 2 — concorrente: "vale eu apurar por conta?". Régua DURA.
        // NUNCA reescrevível — só radar. Exige regional + gancho forte (mata ruído nacional).
        'concorrente' => [
            'base' => 0,
            'peso_regiao_cidade' => 3,
            'peso_regiao_estado' => 2,
            'exige_regiao' => true,
            'exige_gancho' => true, // gancho_top obrigatório (tema leve não basta)
            'corte_quente' => 7,
        ],
    ],
];
