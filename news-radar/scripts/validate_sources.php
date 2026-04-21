<?php

declare(strict_types=1);

/*
 * Valida a lista curada de 54 fontes de SC.
 *  - discovery_mode=feed      -> pinga homepage; descobre feed_url via <link rel="alternate">
 *                                ou tenta /feed, /feed/, /rss, /?feed=rss2. Downgrade para
 *                                html_listing se nenhum feed for XML valido.
 *  - discovery_mode=html_listing -> so exige homepage respondendo 200..399.
 *
 * Gera /home/jr/fontes-validadas.md com 3 grupos.
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

$BOT_UA = 'JornalRazaoBot/1.0 (+https://jornalrazao.com/bot)';
$BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

$SOURCES = [
    ['ND Mais', 'ndmais.com.br', 'feed', 'TIER1'],
    ['NSC Total', 'nsctotal.com.br', 'feed', 'TIER1'],
    ['G1 Santa Catarina', 'g1.globo.com/sc', 'feed', 'TIER1'],
    ['OCP News', 'ocp.news', 'feed', 'TIER1'],
    ['SCC10', 'scc10.com.br', 'feed', 'TIER1'],

    ['Diario do Iguacu', 'diariodoiguacu.com.br', 'feed', 'TIER2'],
    ['O Municipio (Brusque)', 'omunicipio.com.br', 'feed', 'TIER2'],
    ['Engeplus', 'engeplus.com.br', 'feed', 'TIER2'],
    ['Notisul', 'notisul.com.br', 'feed', 'TIER2'],
    ['A Noticia Joinville', 'an.com.br', 'feed', 'TIER2'],
    ['Diario Catarinense', 'dc.com.br', 'feed', 'TIER2'],
    ['Alto Vale Noticias', 'altovalenoticias.com', 'feed', 'TIER2'],
    ['Vale do Itajai Noticias', 'valedoitajainoticias.com.br', 'feed', 'TIER2'],
    ['RBA TV', 'rbatv.com.br', 'feed', 'TIER2'],

    ['VipSocial', 'vipsocial.com.br', 'feed', 'TIER3_TIJUCAS'],
    ['TopElegance', 'topelegance.com.br', 'feed', 'TIER3_TIJUCAS'],
    ['Olhovivocan', 'olhovivocan.com.br', 'feed', 'TIER3_TIJUCAS'],

    ['Pagina 3', 'pagina3.com.br', 'feed', 'TIER3_BC'],
    ['Click Camboriu', 'clickcamboriu.com.br', 'feed', 'TIER3_BC'],
    ['Camboriu News', 'camboriu.news', 'feed', 'TIER3_BC'],
    ['BC Noticias', 'bcnoticias.com.br', 'feed', 'TIER3_BC'],
    ['Bora BC', 'borabc.com.br', 'feed', 'TIER3_BC'],
    ['Minha BC', 'minhabc.com.br', 'feed', 'TIER3_BC'],

    ['Portal Itapema', 'portalitapema.com', 'feed', 'TIER3_LITORAL_NORTE'],
    ['Hora de Bombinhas', 'horadebombinhas.com.br', 'feed', 'TIER3_LITORAL_NORTE'],
    ['Radio Cidade 104.1', 'radiocidadesc.com.br', 'feed', 'TIER3_LITORAL_NORTE'],
    ['Lance Itapema', 'lanceitapema.com.br', 'feed', 'TIER3_LITORAL_NORTE'],
    ['Hora de Porto Belo', 'horadeportobelo.com.br', 'feed', 'TIER3_LITORAL_NORTE'],
    ['Jornal de Navegantes', 'jornaldenavegantes.com.br', 'feed', 'TIER3_LITORAL_NORTE'],
    ['Barra Velha Online', 'barravelhaonline.com.br', 'feed', 'TIER3_LITORAL_NORTE'],
    ['Camboriu Noticias', 'camboriunoticias.com.br', 'feed', 'TIER3_LITORAL_NORTE'],

    ['Jornal Conexao', 'jornalconexao.com.br', 'feed', 'TIER3_FLORIPA'],
    ['SJ Agora', 'sjagora.com.br', 'feed', 'TIER3_FLORIPA'],
    ['Palhoca SC Noticia', 'palhocascnoticia.com.br', 'feed', 'TIER3_FLORIPA'],
    ['Biguacu Ta On', 'biguataon.com.br', 'feed', 'TIER3_FLORIPA'],
    ['Informe Floripa', 'informefloripa.com.br', 'feed', 'TIER3_FLORIPA'],

    ['Joinville News', 'joinvillenews.com.br', 'feed', 'TIER3_OUTROS'],
    ['Noticias Joinville', 'noticiasjoinville.com.br', 'feed', 'TIER3_OUTROS'],
    ['Aconteceu em Joinville', 'aconteceuemjoinville.com.br', 'feed', 'TIER3_OUTROS'],
    ['Saochicoonline', 'saochicoonline.com.br', 'feed', 'TIER3_OUTROS'],
    ['AJ Noticias', 'ajnoticias.com.br', 'feed', 'TIER3_OUTROS'],
    ['Panorama Noticias SC', 'panoramanoticiassc.com.br', 'feed', 'TIER3_OUTROS'],
    ['Correio da Praia', 'jornalcorreiodapraia.com.br', 'feed', 'TIER3_OUTROS'],
    ['Jornal de Pomerode', 'jornaldepomerode.com.br', 'feed', 'TIER3_OUTROS'],
    ['Guabiruba Zeitung', 'guabirubazeitung.com.br', 'feed', 'TIER3_OUTROS'],
    ['Portal Click Sul', 'portalclicksulsc.com.br', 'feed', 'TIER3_OUTROS'],
    ['Noticias Online Lages', 'noticiasonlinelages.com.br', 'feed', 'TIER3_OUTROS'],
    ['Imbituba 360', 'imbituba360.com.br', 'feed', 'TIER3_OUTROS'],
    ['Sao Joaquim Online', 'saojoaquimonline.com.br', 'feed', 'TIER3_OUTROS'],
    ['Made in SMO', 'madeinsmo.com.br', 'feed', 'TIER3_OUTROS'],
    ['2linhas', '2linhas.com', 'feed', 'TIER3_OUTROS'],

    ['PMSC', 'pm.sc.gov.br', 'html_listing', 'OFICIAL'],
    ['PCSC', 'pc.sc.gov.br', 'html_listing', 'OFICIAL'],
    ['CBMSC', 'cbm.sc.gov.br', 'html_listing', 'OFICIAL'],
    ['Defesa Civil SC', 'defesacivil.sc.gov.br', 'html_listing', 'OFICIAL'],
    ['MPSC', 'mpsc.mp.br', 'html_listing', 'OFICIAL'],
    ['TJSC', 'tjsc.jus.br', 'html_listing', 'OFICIAL'],
    ['ALESC', 'alesc.sc.gov.br', 'html_listing', 'OFICIAL'],
];

function normalizeHomepage(string $raw): string
{
    if (!preg_match('~^https?://~i', $raw)) {
        $raw = 'https://' . $raw;
    }
    return rtrim($raw, '/');
}

function makeClient(string $ua): Client
{
    return new Client([
        'headers' => [
            'User-Agent' => $ua,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
        ],
        'allow_redirects' => ['max' => 5, 'strict' => false, 'referer' => false, 'track_redirects' => true],
        'connect_timeout' => 10,
        'timeout' => 15,
        'http_errors' => false,
        'verify' => false, // alguns dominios .gov.br tem cert antigo
    ]);
}

function isFeedXml(string $body): bool
{
    $snippet = substr(ltrim($body), 0, 4096);
    if ($snippet === '') return false;
    // aceita XML prolog ou direto <rss / <feed / <rdf:RDF
    if (!str_starts_with($snippet, '<?xml') && !preg_match('~^\s*<(rss|feed|rdf:RDF)\b~i', $snippet)) {
        return false;
    }
    return (bool) preg_match('~<(rss|feed|rdf:RDF)\b~i', $snippet);
}

function extractFeedLinkFromHtml(string $html, string $baseUrl): ?string
{
    if (!preg_match_all('~<link[^>]+>~i', $html, $m)) return null;
    foreach ($m[0] as $tag) {
        $rel = preg_match('~\brel\s*=\s*["\']?([^"\'\s>]+)~i', $tag, $r) ? strtolower($r[1]) : '';
        $type = preg_match('~\btype\s*=\s*["\']?([^"\'\s>]+)~i', $tag, $t) ? strtolower($t[1]) : '';
        if ($rel !== 'alternate') continue;
        if (!in_array($type, ['application/rss+xml', 'application/atom+xml', 'application/rdf+xml'], true)) continue;
        if (!preg_match('~\bhref\s*=\s*["\']?([^"\'\s>]+)~i', $tag, $h)) continue;
        $href = html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5);
        if (preg_match('~^https?://~i', $href)) return $href;
        if (str_starts_with($href, '//')) return 'https:' . $href;
        if (str_starts_with($href, '/')) {
            $parts = parse_url($baseUrl);
            return $parts['scheme'] . '://' . $parts['host'] . $href;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
    }
    return null;
}

function tryCandidates(Client $client, array $urls): ?array
{
    foreach ($urls as $u) {
        try {
            $resp = $client->request('GET', $u);
            $code = $resp->getStatusCode();
            if ($code < 200 || $code >= 400) continue;
            $body = (string) $resp->getBody();
            if (isFeedXml($body)) {
                return ['url' => $u, 'status' => $code, 'body_preview' => substr($body, 0, 120)];
            }
        } catch (TransferException) {
            continue;
        }
    }
    return null;
}

function fetchHomepage(Client $botClient, Client $browserClient, string $home): array
{
    // tenta bot UA, se 403 ou conn error tenta browser UA
    foreach (['bot' => $botClient, 'browser' => $browserClient] as $label => $client) {
        try {
            $resp = $client->request('GET', $home);
            $code = $resp->getStatusCode();
            if ($code === 403 && $label === 'bot') continue;
            return [
                'ok' => $code >= 200 && $code < 400,
                'status' => $code,
                'body' => (string) $resp->getBody(),
                'ua_used' => $label,
                'effective_url' => $resp->getHeaderLine('X-Guzzle-Redirect-History') ?: $home,
                'error' => null,
            ];
        } catch (ConnectException $e) {
            if ($label === 'bot') continue;
            return ['ok' => false, 'status' => 0, 'body' => '', 'ua_used' => $label, 'effective_url' => $home, 'error' => 'CONN: ' . $e->getMessage()];
        } catch (TransferException $e) {
            if ($label === 'bot') continue;
            return ['ok' => false, 'status' => 0, 'body' => '', 'ua_used' => $label, 'effective_url' => $home, 'error' => 'XFER: ' . $e->getMessage()];
        }
    }
    return ['ok' => false, 'status' => 0, 'body' => '', 'ua_used' => 'none', 'effective_url' => $home, 'error' => 'unreachable'];
}

$botClient = makeClient($BOT_UA);
$browserClient = makeClient($BROWSER_UA);

$results = [];

$total = count($SOURCES);
fwrite(STDERR, "Validando $total fontes...\n");

foreach ($SOURCES as $idx => [$name, $domain, $mode, $tier]) {
    $home = normalizeHomepage($domain);
    fwrite(STDERR, sprintf("[%02d/%02d] %-30s %s ... ", $idx + 1, $total, substr($name, 0, 30), $home));

    $r = fetchHomepage($botClient, $browserClient, $home);

    if (!$r['ok']) {
        $results[] = [
            'name' => $name, 'tier' => $tier, 'homepage' => $home, 'requested_mode' => $mode,
            'status' => 'FAILED',
            'http_code' => $r['status'],
            'error' => $r['error'] ?? ('HTTP ' . $r['status']),
            'final_mode' => null,
            'feed_url' => null,
            'ua_used' => $r['ua_used'],
        ];
        fwrite(STDERR, "FAILED (" . ($r['status'] ?: $r['error'] ?? 'err') . ")\n");
        continue;
    }

    if ($mode === 'html_listing') {
        $results[] = [
            'name' => $name, 'tier' => $tier, 'homepage' => $home, 'requested_mode' => $mode,
            'status' => 'OK_LISTING',
            'http_code' => $r['status'],
            'error' => null,
            'final_mode' => 'html_listing',
            'feed_url' => null,
            'ua_used' => $r['ua_used'],
        ];
        fwrite(STDERR, "OK listing (" . $r['status'] . ", ua=" . $r['ua_used'] . ")\n");
        continue;
    }

    // mode=feed: tenta link rel=alternate, depois candidatos comuns
    $candidates = [];
    $fromHtml = extractFeedLinkFromHtml($r['body'], $home);
    if ($fromHtml) $candidates[] = $fromHtml;

    $candidates = array_merge($candidates, [
        $home . '/feed',
        $home . '/feed/',
        $home . '/rss',
        $home . '/rss.xml',
        $home . '/?feed=rss2',
        $home . '/feed/rss',
        $home . '/feeds/posts/default', // blogger fallback
    ]);
    $candidates = array_values(array_unique($candidates));

    $feed = tryCandidates($botClient, $candidates);
    if ($feed === null) {
        $feed = tryCandidates($browserClient, $candidates);
    }

    if ($feed) {
        $results[] = [
            'name' => $name, 'tier' => $tier, 'homepage' => $home, 'requested_mode' => $mode,
            'status' => 'OK_FEED',
            'http_code' => $r['status'],
            'error' => null,
            'final_mode' => 'feed',
            'feed_url' => $feed['url'],
            'ua_used' => $r['ua_used'],
        ];
        fwrite(STDERR, "OK feed (" . $feed['url'] . ")\n");
    } else {
        $results[] = [
            'name' => $name, 'tier' => $tier, 'homepage' => $home, 'requested_mode' => $mode,
            'status' => 'OK_LISTING_FEED_NOT_FOUND',
            'http_code' => $r['status'],
            'error' => null,
            'final_mode' => 'html_listing',
            'feed_url' => null,
            'ua_used' => $r['ua_used'],
        ];
        fwrite(STDERR, "OK homepage, feed NAO encontrado (downgrade listing)\n");
    }
}

// --- Gera markdown ---
$groupFeed = array_filter($results, fn($r) => $r['status'] === 'OK_FEED');
$groupDowngrade = array_filter($results, fn($r) => $r['status'] === 'OK_LISTING_FEED_NOT_FOUND');
$groupListing = array_filter($results, fn($r) => $r['status'] === 'OK_LISTING');
$groupFailed = array_filter($results, fn($r) => $r['status'] === 'FAILED');

$md = "# Validacao das 54 fontes (SC) — smoke test\n\n";
$md .= "Gerado: " . date('Y-m-d H:i:s') . "\n";
$md .= "UA bot: `$BOT_UA`\n\n";
$md .= "## Resumo\n\n";
$md .= "- Total: " . count($results) . "\n";
$md .= "- Feed valido: " . count($groupFeed) . "\n";
$md .= "- Homepage OK, sem feed detectado (downgrade p/ html_listing): " . count($groupDowngrade) . "\n";
$md .= "- HTML listing (oficiais .gov/.jus): " . count($groupListing) . "\n";
$md .= "- Falharam: " . count($groupFailed) . "\n\n";

$md .= "## 1. Fontes com feed valido (" . count($groupFeed) . ")\n\n";
$md .= "| Tier | Nome | Homepage | Feed descoberto | HTTP | UA |\n|---|---|---|---|---|---|\n";
foreach ($groupFeed as $r) {
    $md .= sprintf("| %s | %s | %s | `%s` | %d | %s |\n", $r['tier'], $r['name'], $r['homepage'], $r['feed_url'], $r['http_code'], $r['ua_used']);
}

$md .= "\n## 2. Homepage OK mas sem feed detectado — downgrade para html_listing (" . count($groupDowngrade) . ")\n\n";
$md .= "| Tier | Nome | Homepage | HTTP | UA |\n|---|---|---|---|---|\n";
foreach ($groupDowngrade as $r) {
    $md .= sprintf("| %s | %s | %s | %d | %s |\n", $r['tier'], $r['name'], $r['homepage'], $r['http_code'], $r['ua_used']);
}

$md .= "\n## 3. Oficiais html_listing (" . count($groupListing) . ")\n\n";
$md .= "| Nome | Homepage | HTTP | UA |\n|---|---|---|---|\n";
foreach ($groupListing as $r) {
    $md .= sprintf("| %s | %s | %d | %s |\n", $r['name'], $r['homepage'], $r['http_code'], $r['ua_used']);
}

$md .= "\n## 4. Falharam (" . count($groupFailed) . ")\n\n";
$md .= "| Tier | Nome | URL testada | HTTP | Erro |\n|---|---|---|---|---|\n";
foreach ($groupFailed as $r) {
    $md .= sprintf("| %s | %s | %s | %d | %s |\n", $r['tier'], $r['name'], $r['homepage'], $r['http_code'], $r['error'] ?? '-');
}

file_put_contents('/home/jr/fontes-validadas.md', $md);
file_put_contents('/home/jr/fontes-validadas.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

fwrite(STDERR, "\nOK: " . count($groupFeed) . " feed + " . count($groupDowngrade) . " listing-downgrade + " . count($groupListing) . " oficiais | FALHAS: " . count($groupFailed) . "\n");
fwrite(STDERR, "Relatorio: /home/jr/fontes-validadas.md\n");
fwrite(STDERR, "JSON:      /home/jr/fontes-validadas.json\n");
