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
    // UMA verdade só — união das listas WhatsApp + NewsRadar. utm_* por prefixo.
    'tracking_params' => [
        // social / share
        'igsh', 'igshid', 'fbclid', 'gclid', 'mode', 'rdid', 'share_url',
        '__twitter_impression', 'guccounter', 'guce_referrer', 'guce_referrer_sig',
        // utm explícitos (além do prefixo utm_*)
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        // mailchimp / analytics
        'mc_cid', 'mc_eid', '_ga',
        // genéricos
        's', 'ref',
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
            'guabiruba', 'gaspar', 'indaial', 'timbó', 'timbo', 'pomerode', 'ilhota',
            'luiz alves', 'botuverá', 'botuvera', 'são francisco do sul',
            'sao francisco do sul', 'garopaba', 'imbituba', 'laguna', 'tubarão', 'tubarao',
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
        // Topo: conquista-superação + indignação/polêmica (o que mais performou no GA).
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
                // indignação / polêmica (reforço)
                'polêmica', 'polemica', 'revoga', 'revogou', 'revogada', 'revogação',
                'após pressão', 'apos pressao', 'sob pressão', 'irregular', 'irregularidade',
                'critica', 'crítica', 'criticado', 'se nega', 'recua', 'recuou', 'protesto',
            ],
        ],
        // Utilidade / gente / economia-regional: serviço que afeta a vida do leitor.
        'utilidade' => [
            'peso' => 4,
            'termos' => [
                'creche', 'vaga', 'programa', 'benefício', 'beneficio', 'gratuito',
                'gratuita', 'apoio', 'auxílio', 'auxilio', 'mutirão', 'mutirao',
                'inscrição', 'inscricao', 'concurso', 'aprovado',
                // economia-regional (pesca/safra/produção que movimenta a região)
                'safra', 'supersafra', 'tainha', 'pescador', 'pesca', 'fartura',
                'colheita', 'produção recorde', 'movimenta',
            ],
        ],
        // Tema leve: feel-good / economia / bicho / meio-ambiente.
        'tema_leve' => [
            'peso' => 3,
            'termos' => [
                'cachorro', 'cão', 'cao', 'gato', 'animal', 'adoção', 'adocao', 'pet',
                'meio ambiente', 'natureza', 'praia', 'sustentável', 'sustentavel',
                'economia', 'emprego', 'renda', 'preço', 'preco', 'custo',
                'festival', 'festa', 'show', 'solidári', 'doação', 'doacao',
                'voluntári', 'criança', 'crianca', 'idoso', 'saúde', 'saude',
            ],
        ],
    ],

    // Rotina/clima: NUNCA esquenta (mesmo regional). Penalidade forte + força frio.
    // Casado no título+markdown e nas categories do frontmatter.
    'rotina_penalty' => [
        'peso' => 10,
        'termos' => [
            'previsao', 'previsão', 'previsão do tempo', 'previsao do tempo',
            'sol predomina', 'risco de chuva', 'temperaturas', 'máximas', 'maximas',
            'mínimas', 'minimas', 'pancadas de chuva', 'frente fria', 'tempo instável',
            'tempo instavel', 'sol entre nuvens', 'céu nublado', 'ceu nublado',
            'fim de semana traz', 'chuva no fim de semana', 'sol e calor',
        ],
        // frontmatter categories (trafilatura) que indicam rotina.
        'categories' => ['clima', 'previsão', 'previsao', 'tempo', 'meteorologia'],
    ],

    // Páginas institucionais / home (sem matéria) — não pontuam (não-pauta).
    'home_paths' => ['', '/', '/home', '/inicio', '/início', '/index.html', '/index.php', '/pt', '/pt-br'],

    // Colunismo / horóscopo / opinião = NÃO-PAUTA. Sinal PRINCIPAL = categories da
    // fonte (pega coluna sem keyword no título). Complemento = poucos termos no
    // TÍTULO. Nunca mata por palavra solta no corpo.
    'colunismo' => [
        'categories' => [
            'coluna', 'colunas', 'colunista', 'colunistas', 'opinião', 'opiniao',
            'horóscopo', 'horoscopo', 'signos', 'astrologia', 'astros',
            'crônica', 'cronica', 'cronistas',
        ],
        'termos_titulo' => [
            'horóscopo', 'horoscopo', 'signos', 'astros', 'previsão dos astros',
            'previsao dos astros',
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
        // Usada pelo caminho WhatsApp (corpus misto nacional/regional).
        'concorrente' => [
            'base' => 0,
            'peso_regiao_cidade' => 3,
            'peso_regiao_estado' => 2,
            'exige_regiao' => true,
            'exige_gancho' => true, // gancho_top obrigatório (tema leve não basta)
            'corte_quente' => 7,
        ],
        // EIXO 2 (FEED) — ponte news_items. Corpus é TODO regional-SC, então
        // "regional + gancho" sozinho seleciona quase tudo. Aqui a barra sobe:
        // exige CIDADE específica (SC genérico não basta) + gancho_top + um 2º sinal
        // (tema/utilidade) via corte alto. Não afeta a régua do WhatsApp.
        'concorrente_feed' => [
            'base' => 0,
            'peso_regiao_cidade' => 3,
            'peso_regiao_estado' => 0,  // estado-SC não pontua (todo o corpus é SC)
            'exige_regiao' => true,
            'exige_cidade' => true,     // precisa bater cidade da cobertura
            'exige_gancho' => true,     // gancho_top obrigatório
            // corte calibrado no corpus 48h (sweep): 11->107q, 12->81q, 13->63q.
            // 13 = exige cidade + gancho + utilidade + tema (sinal forte e múltiplo).
            // Suba/baixe aqui pra afrouxar/apertar o radar de feeds.
            'corte_quente' => 13,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | FASE 2 — colapso por evento (cluster) + juiz LLM
    |---------------------------------------------------------------------------
    | Camadas: (1) gate coarse acima = pré-corte; (2) colapso por evento via
    | news_clusters = 1 representante por história; (3) juiz LLM só nos
    | sobreviventes. Comando: jrlink:juiz.
    */

    'cluster' => [
        // Similaridade entre títulos: overlap ponderado por idf dos tokens
        // (>= min_token_len chars, sem stopwords). Une no union-find se >= corte.
        'overlap_min' => 0.50,
        'min_token_len' => 4,
    ],

    'juiz' => [
        // Versão do prompt — item julgado com a MESMA versão não re-julga (idempotência).
        'prompt_versao' => 'v1',

        // Driver: auto = openai se OPENAI_API_KEY for real; senão claude-cli
        // (claude -p headless, assinatura local). openai reusa o MESMO cliente
        // do enriquecimento NewsRadar (OpenAI::chat), pronto pra quando houver chave.
        'driver' => env('JRLINK_JUIZ_DRIVER', 'auto'),
        'modelo_openai' => env('JRLINK_JUIZ_MODELO_OPENAI', 'gpt-4o-mini'),
        'modelo_claude' => env('JRLINK_JUIZ_MODELO_CLAUDE', 'claude-haiku-4-5-20251001'),

        // Hard cap de chamadas LLM por execução — estourou, ABORTA e reporta.
        'cap_chamadas' => 300,
        // Itens julgados por chamada (lote no mesmo prompt).
        'lote' => 12,

        // Quem vai pro juiz (além do colapso): representante quente coarse sempre;
        // frio coarse só se score >= isto (dá chance de resgate sem julgar lixo).
        'score_frio_minimo' => 8,

        // score_editorial final >= corte (e eh_pauta e escopo não-nacional) => quente.
        'corte_quente_final' => 60,

        /*
        | Âncora GA4 (derivada 2026-06-09, sem depender de calibração manual):
        | cruzamento de jr_sinal_interesse (views por editoria, periodo=total) com
        | jr_titulo_sinal (3.933 títulos, lift de views por tema/gancho).
        | ajuste = (lift_views - 1) * 20, cap [-8, +12]. Corpus "118 títulos do
        | Instagram" não foi localizado no disco — fallback documentado no relatório
        | da Fase 2. Edite aqui pra calibrar.
        |
        | Evidência (lift de views por tema): feel_good_gente 1.70 · famosos 1.66
        | politica 1.38 · economia_negocios 1.32 · animais 1.21 · meio_ambiente 1.15
        | saude 1.04 · seguranca 0.95 · outros 0.86 · transito 0.62
        */
        'ajuste_tema' => [
            'feel_good_gente' => 12,
            'famosos' => 12,
            'politica' => 8,
            'economia_negocios' => 6,
            'animais' => 4,
            'meio_ambiente' => 3,
            'saude' => 1,
            'turismo' => 0,
            'seguranca' => -1,
            'outros' => -3,
            'transito' => -8,
        ],

        /*
        | Pelo tipo_gancho devolvido pelo juiz. Evidência GA4 (lift de views):
        | conquista_superacao 2.28 · indignacao 1.62 · servico 1.18 · demais ~1.0.
        | identidade_sc/feel_good herdam o sinal de feel_good_gente/conquista.
        */
        'ajuste_gancho' => [
            'conquista_superacao' => 10,
            'indignacao' => 8,
            'identidade_sc' => 8,
            'feel_good' => 8,
            'escala' => 4,
            'servico' => 4,
            'curiosidade' => 0,
            'emocao' => 0,
            'solidariedade' => 0,
            'vaquinha' => 0,
            'nenhum' => -5,
        ],

        // Solidariedade/vaquinha NUNCA é quente automático — vai pra fila humana.
        'ganchos_fila_humana' => ['solidariedade', 'vaquinha'],
    ],
];
