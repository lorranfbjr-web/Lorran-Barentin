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
        // Pós-Fase 2 (2026-06-10): 80 fontes SC aprovadas no QA da lista crua
        // (probe HTTP + notícia datada + feed validado). Funil no _tmp_pos2_*.
        'agoralaguna.com.br',
        'agorasul.com.br',
        'alegriafm.net',
        'alvorada945.com.br',
        'araguaiabrusque.com.br',
        'atualfm.com.br',
        'belosf7.com.br',
        'biguanews.com.br',
        'cacador.net',
        'capinzalfm.com.br',
        'catarinas.info',
        'clickcamboriu.com.br',
        'clickxaxim.com.br',
        'clmais.com.br',
        'demaisnews.fm.br',
        'deolhonailha.com.br',
        'diariodacidade.com.br',
        'diplomatafm.com.br',
        'domomento.com.br',
        'empresabiguacuensedenoticias.com',
        'farolblumenau.com',
        'folhababitonga.com.br',
        'folhadeflorianopolis.com.br',
        'folhadovale.com.br',
        'gazetasbs.com.br',
        'grupoasuavoz.com.br',
        'imprensanewssul.com.br',
        'informeblumenau.com',
        'jdv.com.br',
        'jmais.com.br',
        'jornalaw.com.br',
        'jornalcomunidadesantacatarina.com',
        'jornaldeluizalves.com',
        'jornaldomediovale.com.br',
        'jornaljc.com.br',
        'jornalnossailha.com.br',
        'jornalsul.com.br',
        'jornalsuldailha.com.br',
        'jpnewslitoral.com.br',
        'lagesdiario.com.br',
        'lancenoticias.com.br',
        'liderfm1075.com.br',
        'maissul.com.br',
        'marazulnews.com.br',
        'misturebas.com.br',
        'nativacapinzal.net',
        'noticianoato.com.br',
        'noticiasfloripa.com',
        'notiserrasc.com.br',
        'oblumenauense.com.br',
        'oestescnoticias.com.br',
        'oiguassu.com.br',
        'otrentino.com.br',
        'penhaonline.com',
        'portalcdr.com.br',
        'portalcoroado.com.br',
        'portalmakingof.com.br',
        'portalnortedailha.com.br',
        'portalrbv.com.br',
        'portalsaobentonoticias.com.br',
        'radioararangua.com.br',
        'radiocatarinense.com.br',
        'radioclubedecanoinhas.com.br',
        'radiocruzdemalta.com.br',
        'radiomarconi.net',
        'radiopomerode.com.br',
        'redenova.fm.br',
        'redevalenorte.com',
        'santacatarinaempauta.com.br',
        'sbsonline.com.br',
        'schpost.com.br',
        'sombriosincero.com.br',
        'tivinet.com.br',
        'tnsul.com',
        'tribunadafronteira.com.br',
        'tudoaquisc.com.br',
        'tvbv.com.br',
        'tvoesc.com.br',
        'viatv.com.br',
        'vvale.com.br',
        // Re-probe 2026-06-10 (falso-mortos recuperados):
        'guiatemabelardoluz.com.br',
        'saojoaquimonline.com.br',
        'portalitapema.com',
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
        // Merge assistido por LLM (pares limítrofes, "mesmo evento? sim/não").
        // Cap de pares por ciclo; 0 desliga.
        'llm_merge_pares' => 40,
    ],

    /*
    |---------------------------------------------------------------------------
    | MODELOS POR FUNÇÃO (claude-cli) — precisão acima de custo
    |---------------------------------------------------------------------------
    | Cada inteligência do pipeline tem o seu modelo, editável por env. Default
    | Opus 4.8 (claude-opus-4-8), o mais capaz disponível — o juiz julga mérito,
    | o dedup decide "mesmo evento?" e o match_publicado decide "já publicamos
    | esse fato?" (manchetes podem ser totalmente diferentes; erro aqui irrita o
    | editor). Fallback se algum modelo sair do ar: troque o env pro anterior
    | validado, claude-haiku-4-5-20251001 (mais barato, menos preciso), ou
    | qualquer id de modelo que o `claude` aceite em --model. O resolvedor de
    | driver (JuizLlm) ainda escolhe openai vs claude-cli; isto é só o --model
    | do claude-cli por função.
    */
    // Política de custo (30/06/2026): Sonnet no lugar de Opus — qualidade editorial
    // mantida a ~1/5 do consumo. Opus queimava a cota Max (US$ ~79/3d). Reversível
    // por env se algum dia quiser Opus de volta numa função específica.
    'modelos' => [
        'juiz' => env('JRLINK_MODELO_JUIZ', 'claude-sonnet-4-6'),
        'dedup' => env('JRLINK_MODELO_DEDUP', 'claude-sonnet-4-6'),
        'match_publicado' => env('JRLINK_MODELO_MATCH', 'claude-sonnet-4-6'),
    ],

    /*
    | DRIVER POR OPERAÇÃO — economia sem abrir mão da qualidade. As operações
    | MECÂNICAS (dedup de cluster, match com publicados, agrupamento por assunto)
    | não precisam de Claude: vão pro OpenAI (gpt-4o-mini via openai.api_key), que
    | ZERA o consumo da assinatura Max e custa centavos. O JUIZ (mérito editorial)
    | e as REESCRITAS (geração de texto) NÃO estão aqui — seguem o driver global
    | (claude-cli/Sonnet) pela qualidade. Fail-closed: sem chave OpenAI real, o
    | JuizLlm cai pro claude-cli. A chave é o nome da OPERATION logada em jr_juiz_log.
    */
    'drivers' => [
        'cluster_merge' => env('JRLINK_DRIVER_DEDUP', 'openai'),
        'publicado_match' => env('JRLINK_DRIVER_MATCH', 'openai'),
        'assunto_group' => env('JRLINK_DRIVER_ASSUNTO', 'openai'),

        /*
        | GOAL SIMPLIFICAR (03/07) — BLOCO 7: driver de GERAÇÃO de rascunho/kit
        | configurável (JRLINK_RASCUNHO_DRIVER=openai|claude). Lorran avaliou os
        | textos do GPT como iguais/superiores → default openai (A/B de 03/07 no
        | RELATORIO-simplificar). O JUIZ NÃO passa por aqui (prompt/score intactos).
        */
        'reescrita_unificada' => env('JRLINK_RASCUNHO_DRIVER', 'openai') === 'claude' ? 'claude-cli' : 'openai',
        'reescrita_pauta' => env('JRLINK_RASCUNHO_DRIVER', 'openai') === 'claude' ? 'claude-cli' : 'openai',
        'rascunho_civico' => env('JRLINK_RASCUNHO_DRIVER', 'openai') === 'claude' ? 'claude-cli' : 'openai',
        'kit_social' => env('JRLINK_RASCUNHO_DRIVER', 'openai') === 'claude' ? 'claude-cli' : 'openai',
    ],

    /*
    | BLOCO 7 (simplificar 03/07): modelo OpenAI POR OPERAÇÃO — a geração de
    | rascunho usa o melhor GPT da chave (sondado em /v1/models: gpt-5.5,
    | 2026-04-23); baixo volume (poucos textos/dia), qualidade percebida pelo
    | dono manda. Juiz/mecânicas seguem em jrlink.juiz.modelo_openai (mini).
    */
    'modelos_openai' => [
        'reescrita_unificada' => env('JRLINK_RASCUNHO_MODELO', 'gpt-5.5'),
        'reescrita_pauta' => env('JRLINK_RASCUNHO_MODELO', 'gpt-5.5'),
        'rascunho_civico' => env('JRLINK_RASCUNHO_MODELO', 'gpt-5.5'),
        'kit_social' => env('JRLINK_RASCUNHO_MODELO', 'gpt-5.5'),
    ],

    'juiz' => [
        // Versão do prompt — item julgado com a MESMA versão não re-julga (idempotência).
        // v5: efeméride ≠ viral (data comemorativa COM ação concreta de escala/insólita
        // = viral_curiosidade do DNA, ex. 11 mil fatias grátis; só efeméride PURA é
        // eh_pauta=false). Juiz openai sobe pra gpt-5.4-mini (reasoning low).
        // v4: input estruturado por item (FONTE/PUBLICADO/COBERTURA/CONTEXTO/ALERTA
        // fora do truncamento do lead — antes o corte de 280 chars engolia o aviso
        // de IG e o alerta de fato-velho), lead 450 chars, cobertura de portais
        // mensurável, contrato JSON endurecido (todos os ids, na ordem).
        // v3: seção de score ancorada no DNA real do Instagram (jr-ig-dna).
        // v2: regra SEO-washing explícita ("Como foi…", "Tudo o que se sabe…" =
        // eh_pauta false mesmo com fato real). Itens v1 re-julgam aos poucos nos
        // próximos runs.
        'prompt_versao' => 'v5',

        // Driver: auto = openai se OPENAI_API_KEY for real; senão claude-cli
        // (claude -p headless, assinatura local). openai reusa o MESMO cliente
        // do enriquecimento NewsRadar (OpenAI::chat), pronto pra quando houver chave.
        'driver' => env('JRLINK_JUIZ_DRIVER', 'auto'),
        'modelo_openai' => env('JRLINK_JUIZ_MODELO_OPENAI', 'gpt-4o-mini'),
        // modelo_claude: fallback legado. A fonte de verdade do modelo claude-cli
        // por função é jrlink.modelos.* acima; isto cobre chamadas antigas.
        'modelo_claude' => env('JRLINK_JUIZ_MODELO_CLAUDE', 'claude-opus-4-8'),

        // Hard cap de chamadas LLM por execução — estourou, ABORTA e reporta.
        'cap_chamadas' => 300,
        // Itens julgados por chamada (lote no mesmo prompt). 12->24 em 2026-06-10
        // (menos overhead de sessão do claude-cli por item); parse validado com
        // lote real de 24 — se degradar, volte pra 12.
        'lote' => 24,

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

        /*
        | FEW-SHOT do feedback humano — DESLIGADO por default. Ligar = 1 linha
        | no .env: JRLINK_JUIZ_FEWSHOT=true. Quando ligado E houver >= min_votos
        | em jr_pauta_feedback, o prompt do juiz ganha um bloco "calibração do
        | editor" com até max_exemplos (prioriza maiores divergências juiz×humano
        | + cobertura das 3 faixas e dos ganchos mais frequentes). Com a flag
        | OFF o prompt é byte a byte o atual.
        */
        'fewshot' => [
            'enabled' => env('JRLINK_JUIZ_FEWSHOT', false),
            'min_votos' => 30,
            'max_exemplos' => 12,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | NOTIFICAÇÃO — digest de quentes novos pro grupo "Raspador" (Z-API direto)
    |---------------------------------------------------------------------------
    | Caminho próprio (HTTP do Laravel), isolado do disparador n8n. Kill switch:
    | qualquer env vazio = desligado. Dedup permanente em notificado_em.
    */
    'notificacao' => [
        'instance' => env('JRLINK_ALERT_ZAPI_INSTANCE', ''),
        'token' => env('JRLINK_ALERT_ZAPI_TOKEN', ''),
        'client_token' => env('JRLINK_ALERT_ZAPI_CLIENT_TOKEN', ''),
        'grupo' => env('JRLINK_ALERT_GROUP', ''),          // phone do grupo Raspador
        'max_itens' => 10,                                  // cap por mensagem; resto vira "+N"
        'max_links_por_evento' => 6,                        // links por evento multi-portal no digest
        'timezone' => 'America/Sao_Paulo',                  // hora LOCAL da janela
        'silencio_inicio' => 23,                            // janela de silêncio (acumula)
        'silencio_fim' => 6,
        // v4.1: publicação ORIGINAL mais velha que isto (horas) NUNCA vira
        // WhatsApp — catch-up de matéria velha é bloqueado (e marcado como
        // tratado pra não acumular). Já-publicado no site também nunca notifica.
        'max_idade_horas' => 12,
        // Vitrine /radar ao vivo (lê banco na hora, agrupa por assunto) — substitui
        // o snapshot estático antigo _tmp_jrlink/extract.html (Goal radar 2.4).
        'relatorio_url' => 'https://jornaldetijucas.com.br/radar',
    ],

    /*
    |---------------------------------------------------------------------------
    | RASCUNHOS — entrega da pauta reescrita (Montar pauta /radar) no WhatsApp
    |---------------------------------------------------------------------------
    | Grupo PRÓPRIO "JR Rascunhos" (NÃO o Raspador, NÃO os 56 de produção, NÃO o
    | dispatcher n8n). Reusa as MESMAS credenciais Z-API. Kill switch: grupo
    | vazio = desligado (cai pra não enviar). Disparo MANUAL, 1 clique = 1 pauta.
    */
    'rascunhos' => [
        'instance' => env('JRLINK_ALERT_ZAPI_INSTANCE', ''),
        'token' => env('JRLINK_ALERT_ZAPI_TOKEN', ''),
        'client_token' => env('JRLINK_ALERT_ZAPI_CLIENT_TOKEN', ''),
        'grupo' => env('JRLINK_RASCUNHOS_GROUP', ''),       // grupo "JR Rascunhos"
        'og_timeout' => (int) env('JRLINK_RASCUNHOS_OG_TIMEOUT', 7), // s por portal
        'max_fotos' => (int) env('JRLINK_RASCUNHOS_MAX_FOTOS', 6),
    ],

    /*
    | ANTI-LOOP da captura: mensagem que a PRÓPRIA instância manda pro Raspador
    | não pode voltar pro pipeline como "link do WhatsApp". Corta na coleta
    | (jrlink:extract > coletarUrls), nunca no armazenamento bruto.
    |
    | FILTRO DE PRIVACIDADE (Parte A): a captura aceita SOMENTE mensagem de GRUPO
    | (is_group=1) — conversa individual nunca é gravada nem ingerida. Além disso
    | uma denylist NOMINAL de grupos pessoais/próprios e uma denylist por REGEX
    | (os 56 grupos de distribuição "✍️ Jornal Razão #JRxx", que são a SAÍDA do
    | disparador — capturá-los reengoliria o próprio conteúdo do JR). A defesa
    | anti-loop continua sendo a denylist de chat ('Raspador'): o Z-API entrega
    | mensagem própria com from_me=0, então NÃO se confia nesse flag — o nome do
    | chat é a barreira. Aplicado em DOIS pontos: na rota /api/jr-pauta-capture
    | (não grava em disco) e no jrpauta:ingest (não vira linha). Via App\Services\
    | Jr\CapturaFiltro::aceita().
    */
    'captura' => [
        'ignorar_from_me' => true,
        // chats cortados SEMPRE (anti-loop + pessoais nominais). Casa por nome
        // exato (case-insensitive). 'Raspador' = chat de saída (anti-loop).
        'ignorar_chats' => [
            'Raspador',
            'Família Buscapé 🤪',
            'Serviços Executados',
            'Lesadas pela JANNA/ VIRTUOSA oficial',
            'Ideias Mkt Alex',
            // pessoais (revisão da ponte WhatsApp 2026-06-29):
            'Aniversário do DOM.',
            'Fernando de Noronha - 24/04',
            'Holly shit brow',
            // internos do JR / anti-loop (não são imprensa externa):
            'Disparador JR',
            'JURÍDICO - JORNAL RAZÃO',
            'As Tif | Jornal Razão',
            'REDES SOCIAIS POSTAGENS',
            // ANTI-LOOP: grupo de ENTREGA dos rascunhos do /radar. A instância de
            // captura (276) é membro dele; sem este corte, todo rascunho enviado
            // ali seria recapturado e realimentaria o juiz.
            'Rascunhos',
        ],
        // só mensagem de grupo entra (Parte A — opção B).
        'somente_grupo' => true,
        // denylist por regex: os 56 grupos de distribuição do disparador.
        'denylist_regex' => [
            '/Jornal Raz[aã]o\s*#JR\d+/iu',
        ],
    ],

    /*
    | PONTE captura→Radar (Parte C): promove release de TEXTO de grupo (sem link
    | http — link já é tratado por jrlink:extract) pra jr_link_extracao com
    | origem=whatsapp. Janela pela HORA DA CAPTURA (momment), não pelo created_at,
    | pra o backlog não inundar o juiz. O juiz roteia origem=whatsapp DIRETO
    | (pula o gate coarse, calibrado pra manchete), igual ao canal Instagram.
    */
    'bridge_whatsapp' => [
        'janela_horas' => (int) env('JRLINK_WA_BRIDGE_HORAS', 72),
        'min_chars' => (int) env('JRLINK_WA_BRIDGE_MIN_CHARS', 60),
        'cap_por_run' => (int) env('JRLINK_WA_BRIDGE_CAP', 200),
    ],

    /*
    |---------------------------------------------------------------------------
    | RADAR v4.1 — painel "tempo real"
    |---------------------------------------------------------------------------
    | decaimento: score_atual = score do juiz × fator pela IDADE da publicação
    | original do líder do evento (data_pub; sem data_pub usa created_at).
    | Faixas em horas, avaliadas em ordem; idade além da última faixa usa
    | decaimento_apos. O score do juiz NÃO muda no banco — o decaimento é
    | cálculo determinístico de exibição/ordenação (mérito ≠ urgência).
    */
    'radar' => [
        // EM ALTA: mínimo de fontes distintas pra entrar no trending.
        'alta_min_fontes' => 3,
        // fator por faixa de idade (horas). Edite à vontade.
        'decaimento' => [
            ['ate_horas' => 6, 'fator' => 1.0],
            ['ate_horas' => 12, 'fator' => 0.85],
            ['ate_horas' => 24, 'fator' => 0.65],
            ['ate_horas' => 48, 'fator' => 0.4],
        ],
        // idade maior que a última faixa (ex.: catch-up de matéria de 3 dias).
        'decaimento_apos' => 0.4,
    ],

    /*
    |---------------------------------------------------------------------------
    | JÁ-PUBLICADO (v4.2) — jrlink:publicados-sync (scheduler, 30min)
    |---------------------------------------------------------------------------
    | Lê posts PUBLICADOS do WordPress via WPGraphQL (SÓ query, nunca mutation),
    | espelha em jr_publicado e casa contra os eventos quentes do Radar.
    |
    | v4.2: a similaridade textual NÃO decide mais nada — é só um GERADOR DE
    | CANDIDATOS largo (qualquer post das últimas 72h com sobreposição mínima de
    | entidade/cidade/tema com o evento). A DECISÃO é SEMPRE do LLM (Opus) em
    | lote: "este evento do radar é o MESMO FATO que este post já publicado?
    | manchetes podem ser totalmente diferentes — compare fato/pessoas/lugar,
    | não as palavras. sim/não". Isso casa "mesmo fato, manchete diferente"
    | (ex.: criança internada por maus-tratos, dois portais sem palavra em
    | comum) que o textual sozinho perdia. Logado em jr_juiz_log como
    | publicado_match. Match = evento ganha ja_publicado_em/slug → some do Radar
    | e NUNCA notifica. Cruza também com jr_ig_corpus (badge "✅ no IG").
    */
    'publicados' => [
        'endpoint' => 'https://controle.jornalrazao.com/graphql',
        'janela_horas' => 72,     // posts do WP puxados do WPGraphQL por ciclo (mantém o espelho fresco)
        'janela_eventos_horas' => 168, // estoque de eventos quentes confrontado
        // v4.3: o CONFRONTO de match é contra a tabela jr_publicado (acumulada)
        // nos últimos N dias — condenações/desdobramentos saem dias depois do
        // fato; janela curta perdia o "já publicamos isso semana passada".
        'janela_match_dias' => 30,
        'llm_cap_pares' => 80,    // pares evento×post julgados pelo LLM por ciclo (0 desliga)
        // Cap específico da checagem SÍNCRONA no ato de notificar (poucos eventos
        // por digest — generoso pra nunca deixar passar).
        'llm_cap_notificar' => 40,
        // Pré-filtro (gerador de candidatos): generoso de propósito — o LLM é
        // quem corta. min_overlap baixo + janela de candidatos por entidade rara.
        'prefiltro_overlap_min' => 0.12, // sobreposição idf mínima evento×post
        'prefiltro_max_cand_por_evento' => 6, // top-K posts candidatos por evento
    ],

    // Chave leve da aba Radar do painel React (middleware JrPanelKey).
    'painel_key' => env('JRLINK_PANEL_KEY', ''),

    /*
    |---------------------------------------------------------------------------
    | CANAL INSTAGRAM — perfis só-IG entram na MESMA esteira (origem instagram)
    |---------------------------------------------------------------------------
    | jrlink:instagram-poll (15min): ator Apify nos perfis abaixo, máx
    | max_posts_por_perfil por poll, item na régua com categoria concorrente
    | (radar, nunca reescreve) e dedup permanente por shortcode (url do post).
    | Kill switch: JRLINK_IG_ENABLED=false OU profiles vazio.
    */
    'instagram' => [
        'enabled' => env('JRLINK_IG_ENABLED', true),
        'actor_id' => 'apify~instagram-post-scraper', // validado no corpus do DNA
        'max_posts_por_perfil' => 3,
        // round-robin: perfis visitados por poll (1 = rotação completa em
        // ciclo×N polls; subir = mais cobertura, mais custo proporcional).
        'perfis_por_poll' => 1,
        'profiles' => [
            'sos_naufragados', 'calamidadeoficial', 'florianopolis24h',
            'conexao_geoclima', 'pistalimpa', 'reporter.sergioguimaraes',
            'floripamilgrau', 'palhocamilgrau', 'saojosemilgrauu', 'portalnortedailha',
        ],
        // legenda com menos que isto = sem conteúdo útil, pula (logado).
        'min_legenda' => 25,
        // alerta no Raspador após N falhas seguidas; supressão de re-alerta (min).
        'falhas_para_alerta' => 3,
        'supressao_alerta_min' => 360,
    ],
];
