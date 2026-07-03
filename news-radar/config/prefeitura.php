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
 * url_sessao=URL  → priming de sessão em OUTRA url antes do GET real (Blumenau:
 * o AJAX pagina-busca.php só responde com cookie de sessão da listagem).
 * Ligadas 03/07: Brusque (atende64 igual Indaial + fallback de URL rotativa em
 * segueUrlRotativa) e Blumenau (regex no AJAX, data por card).
 * FORA (motivo real, decisão futura): Jaraguá do Sul e Florianópolis (firewall
 * dropa TCP do VPS, mesmo caso ALESC), São José (Cloudflare challenge) —
 * prontas pra ligar depois, ativo=false.
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
        // Atende.net v2 igual Indaial; quando o dados= vier como stub de URL
        // rotativa, o conector segue a URL (2º passo em segueUrlRotativa).
        ['cidade' => 'Brusque', 'estrategia' => 'atende64', 'ativo' => true,
            'url' => 'https://www.brusque.sc.gov.br/cidadao/noticia',
            'base' => 'https://www.brusque.sc.gov.br', 'rotina' => '49348'],
        // Listagem visível é casca: cards vêm por AJAX (pagina-busca.php) que
        // só responde com cookie de sessão da listagem (url_sessao). No AJAX a
        // data é POR CARD ("Sectur - 02/07/2026"), sem cabeçalho de grupo.
        ['cidade' => 'Blumenau', 'estrategia' => 'regex', 'ativo' => true,
            'url' => 'https://www.blumenau.sc.gov.br/controller/pagina-busca.php?pagina=1',
            'url_sessao' => 'https://www.blumenau.sc.gov.br/listagem/noticias',
            'pattern' => '/<a href="(?<url>https?:\/\/www\.blumenau\.sc\.gov\.br\/[^"]+)">[\s\S]{0,500}?<span class="descricao">(?<data>[^<]*)<\/span>\s*(?<titulo>[^<]+)<\/a>/u'],
    ],
];
