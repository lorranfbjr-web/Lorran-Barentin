<?php

/*
 * BLOCO 3 (Goal 02/07) — Notícia INSTITUCIONAL das prefeituras de interesse
 * (tier1 anunciantes GAM + núcleo). Scoping real 02/07/2026 (sonda educada,
 * 1 req/s/host): 15/19 raspáveis do VPS. Estratégias:
 *   rss      — feed RSS/Atom (data mais limpa; preferida)
 *   api      — JSON aberto (mesmo vendor em BC e Itajaí: {noticias:[{newsId,title,published}]})
 *   regex    — listagem server-side, regex com grupos nomeados url/titulo/data
 *   atende64 — Atende.net v2: <consulta ... dados="BASE64"> com JSON embutido
 * cookie_gate=true → anti-bot nginx de cookie (1º GET 403 seta cookie, 2º passa).
 * FORA (motivo real, decisão futura): Jaraguá do Sul e Florianópolis (firewall
 * dropa TCP do VPS, mesmo caso ALESC), São José (Cloudflare challenge),
 * Brusque (fragmento base64 com URL rotativa) e Blumenau (data em cabeçalho de
 * grupo por extenso) — prontas pra ligar depois, ativo=false.
 */
return [
    'scoring' => [
        'modelo' => env('JR_PREF_SCORING_MODELO', 'claude-sonnet-4-6'),
    ],

    'max_por_fonte' => (int) env('JR_PREF_MAX_POR_FONTE', 15),

    'fontes' => [
        ['cidade' => 'Tijucas', 'estrategia' => 'rss', 'ativo' => true,
            'url' => 'https://tijucas.sc.gov.br/feeds/noticias.xml'],
        ['cidade' => 'Itapema', 'estrategia' => 'rss', 'ativo' => true,
            'url' => 'https://www.itapema.sc.gov.br/feed/?post_type=noticia'],
        ['cidade' => 'Navegantes', 'estrategia' => 'rss', 'ativo' => true, 'cookie_gate' => true,
            'url' => 'https://navegantes.sc.gov.br/feed/'],
        ['cidade' => 'Porto Belo', 'estrategia' => 'rss', 'ativo' => true, 'cookie_gate' => true,
            'url' => 'https://portobelo.sc.gov.br/feed/'],
        ['cidade' => 'Biguaçu', 'estrategia' => 'rss', 'ativo' => true, 'cookie_gate' => true,
            'url' => 'https://www.bigua.sc.gov.br/feed/'],
        ['cidade' => 'Governador Celso Ramos', 'estrategia' => 'rss', 'ativo' => true, 'cookie_gate' => true,
            'url' => 'https://governadorcelsoramos.sc.gov.br/category/noticias/feed/'],

        ['cidade' => 'Balneário Camboriú', 'estrategia' => 'api', 'ativo' => true,
            'url' => 'https://sim.bc.sc.gov.br/portal-municipio/api/noticias',
            'url_noticia' => 'https://www.bc.sc.gov.br/noticia/{id}'],
        ['cidade' => 'Itajaí', 'estrategia' => 'api', 'ativo' => true,
            'url' => 'https://api-portal.itajai.sc.gov.br/portaladm-pmitajai/api/noticias/ordem/recente/',
            'url_noticia' => 'https://itajai.sc.gov.br/noticias/{id}/{slug}'],

        ['cidade' => 'Lages', 'estrategia' => 'regex', 'ativo' => true,
            'url' => 'https://www.lages.sc.gov.br/noticias',
            'pattern' => '/href="(?<url>https:\/\/www\.lages\.sc\.gov\.br\/noticia-descricao\/\d+\/[^"]+)"[\s\S]*?<small>(?<data>\d{2}\/\d{2}\/\d{4}) [\d:]+<\/small>[\s\S]*?<h2>(?<titulo>[^<]+)<\/h2>/u'],
        ['cidade' => 'Criciúma', 'estrategia' => 'regex', 'ativo' => true,
            'url' => 'https://www.criciuma.sc.gov.br/noticiaTodos',
            'pattern' => '/<a href="(?<url>https:\/\/www\.criciuma\.sc\.gov\.br\/noticia\/[^"]+)">[\s\S]*?<h2>\s*<span[^>]*>\/\/<\/span>\s*(?<titulo>[\s\S]*?)\s*<\/h2>\s*<sub>Data:\s*(?<data>[\d\/]+)/u'],
        ['cidade' => 'Chapecó', 'estrategia' => 'regex', 'ativo' => true, 'charset' => 'ISO-8859-1',
            'url' => 'https://chapeco.sc.gov.br/noticias', 'base' => 'https://chapeco.sc.gov.br/',
            'pattern' => '/(?:<time class="news-date"[^>]*>(?<data>\d{2}\/\d{2}\/\d{4})[^<]*<\/time>[\s\S]{0,600}?)?href="(?<url>noticia\/\d+\/[^"]+)"[^>]*class="news-title">(?<titulo>[^<]+)</u'],
        ['cidade' => 'Joinville', 'estrategia' => 'regex', 'ativo' => true,
            'url' => 'https://www.joinville.sc.gov.br/?post_type=noticia&s=',
            'pattern' => '/<p class="h3">\s*<a href="(?<url>https:\/\/www\.joinville\.sc\.gov\.br\/noticias\/[^"]+)">(?<titulo>[^<]+)<\/a>[\s\S]*?pmj-result-detail-date\'>\s*(?<data>[0-3]?\d\/[01]\d\/\d{4})/u'],
        ['cidade' => 'Palhoça', 'estrategia' => 'regex', 'ativo' => true, 'charset' => 'ISO-8859-1',
            'url' => 'https://palhoca.atende.net/cidadao/noticia', 'base' => 'https://palhoca.atende.net',
            'pattern' => '/\'titulo\':\s*\'(?<titulo>[^\']*)\'[\s\S]{0,800}?\'data\':\s*\'(?<data>\d{2}\/\d{2}\/\d{4})\'[\s\S]{0,800}?\'link\':\s*"(?<url>[^"]*)"/u'],

        ['cidade' => 'Indaial', 'estrategia' => 'atende64', 'ativo' => true,
            'url' => 'https://indaial.atende.net/cidadao/noticia',
            'base' => 'https://indaial.atende.net', 'rotina' => '49348'],

        // ─── prontas-mas-desativadas (bloqueio/complexidade — decisão futura) ───
        ['cidade' => 'Jaraguá do Sul', 'estrategia' => 'regex', 'ativo' => false,
            'url' => 'https://www.jaraguadosul.sc.gov.br/noticias',
            'obs' => 'firewall dropa TCP do VPS (igual ALESC); site Next.js SSR raspável de outro IP'],
        ['cidade' => 'São José', 'estrategia' => 'regex', 'ativo' => false,
            'url' => 'https://www.saojose.sc.gov.br/noticias',
            'obs' => 'Cloudflare Managed Challenge — precisa headless/browser'],
        ['cidade' => 'Florianópolis', 'estrategia' => 'regex', 'ativo' => false,
            'url' => 'https://www.pmf.sc.gov.br/noticias/index.php',
            'obs' => 'firewall dropa TCP do VPS (bloqueio amplo de datacenter)'],
        ['cidade' => 'Brusque', 'estrategia' => 'regex', 'ativo' => false,
            'url' => 'https://www.brusque.sc.gov.br/cidadao/noticia',
            'obs' => 'Atende.net v2 com fragmento base64 de URL rotativa — fluxo 2 passos a implementar'],
        ['cidade' => 'Blumenau', 'estrategia' => 'regex', 'ativo' => false,
            'url' => 'https://www.blumenau.sc.gov.br/listagem/noticias',
            'obs' => 'listagem ok, mas data em cabeçalho de grupo por extenso — parser dedicado a fazer'],
    ],
];
