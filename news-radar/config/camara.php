<?php

/**
 * Conector SAPL (câmaras) — Radar Cívico, FASE 2. ADITIVO e ISOLADO: config
 * próprio, tabela própria (jr_camara_proposicoes), comandos próprios. NÃO toca
 * DOM, juiz, captura nem dispatcher. Reaproveita o FARO (Sonnet, dual-lens 🔴/🟢)
 * com lente CÂMARA. source=camara.
 *
 * SAPL (Interlegis, open-source) expõe REST: /api/materia/materialegislativa/
 * (paginação DRF, total_entries), /api/materia/tipomaterialegislativa/ (id→sigla/
 * descrição — VARIA por instância, por isso resolvemos por DESCRIÇÃO em runtime)
 * e /api/base/autor/ (id→nome). Acesso público, sem auth.
 *
 * RECON 29/06/2026 (probe sapl.<cidade>.sc.leg.br): das 32 prioritárias, só 5
 * respondem com API SAPL viva. As demais são NXDOMAIN ou portal próprio (Floripa
 * = CMF custom, Joinville = LEGISOFT) — anotadas pra raspador dedicado, NÃO aqui.
 */
return [
    'user_agent' => env('JRCAM_UA', 'JornalRazaoBot/0.1 (+contato lorranfbjr@gmail.com; pesquisa jornalistica proposicoes camaras SC)'),

    // Pausa entre requisições (politeness).
    'pausa_seg' => (float) env('JRCAM_PAUSA', 0.6),

    // Câmaras com SAPL vivo (recon RE-CONFIRMADO 29/06/2026, probe ?ano=2026/2025
    // + última proposição via o=-data_apresentacao). 'recencia' = última matéria
    // de fato na API. SÓ as 'vivo'=true alimentam o forward (jr:camara-ingest pula
    // as mortas por padrão; --incluir-mortas força). As mortas ficam aqui só como
    // documentação de cobertura — NÃO são pauta atual e o radar as filtra por data.
    //
    // VIVAS (2): São Bento do Sul (2026), Rio do Sul (2026).
    // MORTAS (4): SAPL existe mas parou — Canoinhas (2024), Imbuia (2023),
    //   Tijucas (2022), São José (2020). Onde publicam hoje (probe VPS 29/06):
    //   Imbuia AINDA roda SAPL/Interlegis (só não alimenta desde jun/2023);
    //   Tijucas tem portal próprio (openresty, sem Interlegis);
    //   Canoinhas e São José bloqueiam probe (503 LiteSpeed) — recon manual pendente.
    // Demais ~31 prioritárias = AUSENTE (sem SAPL no host padrão) — ver scoping.
    //
    // RE-SONDA 02/07/2026 (D1, 26 cidades, direto no endpoint): NENHUM SAPL novo
    // vivo — só os 2 já configurados (SBS 1.021 itens 2026; Rio do Sul 557).
    // Onde as demais publicam HOJE (plataforma × viabilidade de conector):
    //   FÁCIL : Itapema (ELegis2 site.itapema.sc.leg.br/elegis2, HTML público
    //           paginado, qtd= até 1000); São João Batista (Legislador WEB
    //           legislador.com.br?WCI=ProjetoConsulta&ID=34, GET paginado);
    //           Balneário Camboriú (LEGISWEB/CamaraSPA, HTML estático indexável).
    //   MÉDIA : Porto Belo (Legislador WEB ID=337), Guabiruba (Legislador WEB),
    //           Bombinhas (Vision), Camboriú (SOFTCAM), Brusque (IPM) — TLS
    //           reset/WAF contra IP de datacenter, conteúdo existe (indexado).
    //   BESPOKE/INVIÁVEL: o bloco LEGISOFT (Tijucas, Canelinha, Nova Trento,
    //           Biguaçu, Gaspar, Lages, Blumenau, Navegantes, Criciúma,
    //           Joinville) + Palhoça/Floripa/São José/Chapecó — reCAPTCHA ou
    //           Cloudflare bloqueando raspagem automatizada deste VPS.
    // Tabela completa: goals/RELATORIO-matar-pendencias-20260702.md (D1).
    // SONDA 02/07/2026 (goal radar-interesse, CAMARAS-sonda.json): 3 SAPLs novos
    // VIVOS nas cidades de interesse — Luiz Alves (313 itens 2026), Schroeder
    // (299), Santo Amaro da Imperatriz (298). Forward-first + backfill leve 2025+.
    'camaras' => [
        ['cidade' => 'São Bento do Sul', 'host' => 'sapl.saobentodosul.sc.leg.br',   'recencia' => '2026-06-29', 'vivo' => true],
        ['cidade' => 'Rio do Sul',       'host' => 'sapl.camarariodosul.sc.gov.br',   'recencia' => '2026-06-26', 'vivo' => true],
        ['cidade' => 'Luiz Alves',       'host' => 'sapl.luizalves.sc.leg.br',        'recencia' => '2026-07-02', 'vivo' => true],
        ['cidade' => 'Schroeder',        'host' => 'sapl.schroeder.sc.leg.br',        'recencia' => '2026-07-02', 'vivo' => true],
        ['cidade' => 'Santo Amaro da Imperatriz', 'host' => 'sapl.santoamarodaimperatriz.sc.leg.br', 'recencia' => '2026-07-02', 'vivo' => true],
        ['cidade' => 'Canoinhas',        'host' => 'sapl.canoinhas.sc.leg.br',        'recencia' => '2024-07-31', 'vivo' => false],
        ['cidade' => 'Imbuia',           'host' => 'sapl.imbuia.sc.leg.br',           'recencia' => '2023-06-29', 'vivo' => false],
        ['cidade' => 'Tijucas',          'host' => 'sapl.tijucas.sc.leg.br',          'recencia' => '2022-11-22', 'vivo' => false],
        ['cidade' => 'São José',         'host' => 'sapl.saojose.sc.leg.br',          'recencia' => '2020-09-16', 'vivo' => false],
    ],

    // ── LEGISLADOR WEB (legislador.com.br — HTML ASP server-rendered, SEM gate) ──
    // Sonda 02/07/2026 (CAMARAS-sonda.json): 7 cidades de interesse no mesmo
    // sistema; UM conector genérico parametrizado por ID cobre todas. A página
    // ProjetoTramite lista os projetos EM TRAMITAÇÃO (todos os anos) com tipo,
    // nº/ano, origem (L/E), data e ementa — 1 GET por câmara.
    // NB: o ASP responde 302 'Objeto movido' MAS com o conteúdo no corpo —
    // o conector NÃO segue redirect (seguir = perder o corpo). Windows-1252.
    'legislador' => [
        'base' => env('JRCAM_LEGISLADOR_BASE', 'https://www.legislador.com.br/LegisladorWEB.ASP'),
        // backfill leve: só projetos de ano >= este entram (forward-first)
        'ano_min' => (int) env('JRCAM_LEGISLADOR_ANO_MIN', 2025),
        'cidades' => [
            ['cidade' => 'Penha',            'id' => 2],
            ['cidade' => 'Jaraguá do Sul',   'id' => 5],
            ['cidade' => 'São João Batista', 'id' => 34],
            ['cidade' => 'Guabiruba',        'id' => 40],
            ['cidade' => 'Corupá',           'id' => 79],
            ['cidade' => 'Ilhota',           'id' => 92],
            ['cidade' => 'Porto Belo',       'id' => 337],
        ],
    ],

    // ── ITAPEMA (elegis2 — HTML aberto em site.itapema.sc.leg.br) ──
    // Sonda 02/07/2026: lista-projeto paginada (30/pág, ?pagina=N), detalhe por
    // cod_proposicao (data de criação/autor/situação), ementa SÓ no PDF da
    // íntegra (extraída via PyMuPDF do venv scripts/pdf-venv, 1ª página).
    // lista-propositura = Indicação/Requerimento (ruído, mesma régua dos
    // tipos_relevantes do SAPL) — fica FORA por decisão 02/07.
    'itapema' => [
        'base' => env('JRCAM_ITAPEMA_BASE', 'https://site.itapema.sc.leg.br'),
        'ano_min' => (int) env('JRCAM_ITAPEMA_ANO_MIN', 2025),
        'max_paginas' => (int) env('JRCAM_ITAPEMA_MAX_PAGINAS', 12),
        'python' => base_path('scripts/pdf-venv/bin/python'),
    ],

    // ── FIRECRAWL (Fase 2 das câmaras — SoftCâmaras + LEGISOFT, 30 cidades) ──
    // Sonda 02/07/2026: os 2 grupos ficam atrás de reCAPTCHA invisível
    // (SoftCâmaras = 'lsrecaptcha', IP 170.81.43.171; LEGISOFT = /captcha,
    // IP 45.164.94.x) que bloqueia curl de QUALQUER IP. Via sancionada: o
    // serviço comercial Firecrawl renderiza a página publicada — NÃO fazemos
    // bypass de verificação de bot por conta própria.
    // BLOQUEADO 02/07: sem FIRECRAWL_API_KEY no .env. Pronto-pra-plugar —
    // roteiro no docblock do FirecrawlConector. 'poc' => true marca as 2
    // câmaras da prova (1 por plataforma) pro jr:firecrawl-ingest --poc.
    // Grafia das 30 cidades validada contra DomGeografia::municipios() 02/07.
    // Chapecó (SPA Cittatec — mapear XHR pode dispensar Firecrawl) e Joinville
    // (Cloudflare) são FASE 3 — de fora daqui de propósito. ALESC parqueada.
    'firecrawl' => [
        'ativo' => (bool) env('JRCAM_FIRECRAWL_ATIVO', false),
        'api_key' => env('FIRECRAWL_API_KEY', ''),
        'api_base' => env('FIRECRAWL_API_BASE', 'https://api.firecrawl.dev/v1'),
        'ano_min' => (int) env('JRCAM_FIRECRAWL_ANO_MIN', 2025),
        // ms de espera pós-load no render (reCAPTCHA invisível + hidratação)
        'wait_ms' => (int) env('JRCAM_FIRECRAWL_WAIT_MS', 5000),
        'cidades' => [
            // SOFTCAMARAS — a LISTAGEM /proposicoes é gated; a via que FUNCIONA
            // (provada 02/07) é search→scrape de URL PROFUNDA com stealth (BLOCO 5).
            // firecrawl_ativo=true SÓ na PoC (Canelinha + Nova Trento, coladas em
            // Tijucas); as demais estão PRONTAS-MAS-DESATIVADAS — escalar consome
            // crédito metered e é decisão de plano do Lorran (Hobby ~R$83/mês vs
            // runner headless no PC do escritório).
            ['cidade' => 'Canelinha',             'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaracanelinha.sc.gov.br',      'url' => 'https://www.camaracanelinha.sc.gov.br/proposicoes',      'poc' => true, 'firecrawl_ativo' => true],
            ['cidade' => 'Nova Trento',           'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaranovatrento.sc.gov.br',     'url' => 'https://www.camaranovatrento.sc.gov.br/proposicoes',     'firecrawl_ativo' => true],
            ['cidade' => 'Bombinhas',             'plataforma' => 'softcamaras', 'url_base' => 'https://www.camarabombinhas.sc.gov.br',      'url' => 'https://www.camarabombinhas.sc.gov.br/proposicoes'],
            ['cidade' => 'Camboriú',              'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaracamboriu.sc.gov.br',       'url' => 'https://www.camaracamboriu.sc.gov.br/proposicoes'],
            ['cidade' => 'Balneário Camboriú',    'plataforma' => 'softcamaras', 'url_base' => 'https://www.balneariocamboriu.sc.leg.br',    'url' => 'https://www.balneariocamboriu.sc.leg.br/proposicoes'],
            ['cidade' => 'Navegantes',            'plataforma' => 'softcamaras', 'url_base' => 'https://www.navegantes.sc.leg.br',           'url' => 'https://www.navegantes.sc.leg.br/proposicoes'],
            ['cidade' => 'Balneário Piçarras',    'plataforma' => 'softcamaras', 'url_base' => 'https://www.camarapicarras.sc.gov.br',       'url' => 'https://www.camarapicarras.sc.gov.br/proposicoes'],
            ['cidade' => 'Brusque',               'plataforma' => 'softcamaras', 'url_base' => 'https://www.camarabrusque.sc.gov.br',        'url' => 'https://www.camarabrusque.sc.gov.br/proposicoes'],
            ['cidade' => 'Botuverá',              'plataforma' => 'softcamaras', 'url_base' => 'https://www.camarabotuvera.sc.gov.br',       'url' => 'https://www.camarabotuvera.sc.gov.br/proposicoes'],
            ['cidade' => 'Pomerode',              'plataforma' => 'softcamaras', 'url_base' => 'https://www.cmpomerode.sc.gov.br',           'url' => 'https://www.cmpomerode.sc.gov.br/proposicoes'],
            ['cidade' => 'Indaial',               'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaraindaial.sc.gov.br',        'url' => 'https://www.camaraindaial.sc.gov.br/proposicoes'],
            ['cidade' => 'Timbó',                 'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaratimbo.sc.gov.br',          'url' => 'https://www.camaratimbo.sc.gov.br/proposicoes'],
            ['cidade' => 'Guaramirim',            'plataforma' => 'softcamaras', 'url_base' => 'https://www.guaramirim.sc.leg.br',           'url' => 'https://www.guaramirim.sc.leg.br/proposicoes'],
            ['cidade' => 'Massaranduba',          'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaramassaranduba.sc.gov.br',   'url' => 'https://www.camaramassaranduba.sc.gov.br/proposicoes'],
            ['cidade' => 'Florianópolis',         'plataforma' => 'softcamaras', 'url_base' => 'https://www.cmf.sc.gov.br',                  'url' => 'https://www.cmf.sc.gov.br/proposicoes'],
            ['cidade' => 'São José',              'plataforma' => 'softcamaras', 'url_base' => 'https://www.cmsj.sc.gov.br',                 'url' => 'https://www.cmsj.sc.gov.br/proposicoes'],
            ['cidade' => 'Palhoça',               'plataforma' => 'softcamaras', 'url_base' => 'https://www.cmp.sc.gov.br',                  'url' => 'https://www.cmp.sc.gov.br/proposicoes'],
            ['cidade' => 'Biguaçu',               'plataforma' => 'softcamaras', 'url_base' => 'https://www.cmb.sc.gov.br',                  'url' => 'https://www.cmb.sc.gov.br/proposicoes'],
            ['cidade' => 'Governador Celso Ramos', 'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaragcr.sc.gov.br',           'url' => 'https://www.camaragcr.sc.gov.br/proposicoes'],
            ['cidade' => 'Antônio Carlos',        'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaraantoniocarlos.sc.gov.br',  'url' => 'https://www.camaraantoniocarlos.sc.gov.br/proposicoes'],
            ['cidade' => 'Nova Veneza',           'plataforma' => 'softcamaras', 'url_base' => 'https://www.cvnv.sc.gov.br',                 'url' => 'https://www.cvnv.sc.gov.br/proposicoes'],
            ['cidade' => 'Xaxim',                 'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaraxaxim.sc.gov.br',          'url' => 'https://www.camaraxaxim.sc.gov.br/proposicoes'],
            ['cidade' => 'Major Gercino',         'plataforma' => 'softcamaras', 'url_base' => 'https://www.camaramajorgercino.sc.gov.br',   'url' => 'https://www.camaramajorgercino.sc.gov.br/proposicoes'],
            // LEGISOFT — docs em /documento/<slug>-<id>; Blumenau usa o
            // subdomínio digital.* com filtros na URL (tipo:…/ano:…)
            ['cidade' => 'Tijucas',               'plataforma' => 'legisoft',    'url_base' => 'https://www.camaratijucas.sc.gov.br',        'url' => 'https://www.camaratijucas.sc.gov.br/pag/proposicoes-legislativas', 'poc' => true],
            ['cidade' => 'Itajaí',                'plataforma' => 'legisoft',    'url_base' => 'https://cvi.sc.gov.br',                      'url' => 'https://cvi.sc.gov.br/'],
            ['cidade' => 'Gaspar',                'plataforma' => 'legisoft',    'url_base' => 'https://camaragaspar.sc.gov.br',             'url' => 'https://camaragaspar.sc.gov.br/'],
            ['cidade' => 'Criciúma',              'plataforma' => 'legisoft',    'url_base' => 'https://camaracriciuma.sc.gov.br',           'url' => 'https://camaracriciuma.sc.gov.br/'],
            ['cidade' => 'Içara',                 'plataforma' => 'legisoft',    'url_base' => 'https://camaraicara.sc.gov.br',              'url' => 'https://camaraicara.sc.gov.br/'],
            ['cidade' => 'Forquilhinha',          'plataforma' => 'legisoft',    'url_base' => 'https://camaraforquilhinha.sc.gov.br',       'url' => 'https://camaraforquilhinha.sc.gov.br/'],
            ['cidade' => 'Blumenau',              'plataforma' => 'legisoft',    'url_base' => 'https://digital.camarablu.sc.gov.br',        'url' => 'https://digital.camarablu.sc.gov.br/documentos/tipo:legislativo-2/ano:2026/'],
        ],
    ],

    // Tipos de matéria RELEVANTES (lei-making): selecionados por DESCRIÇÃO
    // normalizada (sigla/id variam por instância). Excluímos ruído: indicação,
    // moção, requerimento, emenda, certificado, parecer, prestação de contas.
    'tipos_relevantes' => [
        'projeto de lei',
        'decreto legislativo',
        'projeto de resolucao',
        'emenda a lei organica',
    ],

    // Teto de páginas (10 itens/página) por câmara numa varredura.
    'max_paginas' => (int) env('JRCAM_MAX_PAGINAS', 60),

    // FORWARD: quantos anos varrer recente-primeiro (ano corrente + N-1 anteriores).
    // 2 = ano corrente + anterior — pega proposição de fim do ano passado ainda
    // viva como pauta, sem cair no histórico inteiro (isso é o --backfill).
    'janela_anos' => (int) env('JRCAM_JANELA_ANOS', 2),

    // RADAR: só mostra proposição com data_pub nos últimos N meses — rebaixa feeds
    // mortos (Canoinhas 2024, Tijucas 2022, São José 2020) que não são pauta atual.
    'radar_meses' => (int) env('JRCAM_RADAR_MESES', 18),

    // ── faro (scoring), separado do juiz/Opus; Sonnet trocável por env ──
    'scoring' => [
        'modelo' => env('JRCAM_MODELO_SCORING', 'claude-sonnet-4-6'),
        'lote' => (int) env('JRCAM_LOTE', 6),
        'cap_chamadas' => (int) env('JRCAM_CAP', 500),
    ],
];
