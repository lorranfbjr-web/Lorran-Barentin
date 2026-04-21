<?php

declare(strict_types=1);

/*
 * 2a passada: nos 7 dominios que deram 403 na homepage, tenta feed direto
 * com UA de browser. Alguns WP/Cloudflare protegem home mas deixam /feed/ aberto.
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;

$BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
$BOT_UA = 'JornalRazaoBot/1.0 (+https://jornalrazao.com/bot)';

$TARGETS = [
    ['Olhovivocan', 'olhovivocan.com.br', 'TIER3_TIJUCAS'],
    ['Click Camboriu', 'clickcamboriu.com.br', 'TIER3_BC'],
    ['BC Noticias', 'bcnoticias.com.br', 'TIER3_BC'],
    ['Radio Cidade 104.1', 'radiocidadesc.com.br', 'TIER3_LITORAL_NORTE'],
    ['Jornal Conexao', 'jornalconexao.com.br', 'TIER3_FLORIPA'],
    ['Jornal de Pomerode', 'jornaldepomerode.com.br', 'TIER3_OUTROS'],
];

function isFeedXml(string $body): bool
{
    $snippet = substr(ltrim($body), 0, 4096);
    if ($snippet === '') return false;
    if (!str_starts_with($snippet, '<') || !preg_match('~^\s*(<\?xml|<rss|<feed|<rdf:RDF)~i', $snippet)) {
        return false;
    }
    return (bool) preg_match('~<(rss|feed|rdf:RDF)\b~i', $snippet);
}

function makeClient(string $ua): Client
{
    return new Client([
        'headers' => [
            'User-Agent' => $ua,
            'Accept' => 'application/rss+xml,application/xml,text/xml,application/atom+xml;q=0.9,*/*;q=0.5',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
        ],
        'allow_redirects' => ['max' => 5],
        'connect_timeout' => 10,
        'timeout' => 15,
        'http_errors' => false,
        'verify' => false,
    ]);
}

$browser = makeClient($BROWSER_UA);
$bot = makeClient($BOT_UA);

$results = [];
foreach ($TARGETS as [$name, $domain, $tier]) {
    $home = 'https://' . $domain;
    $candidates = [
        $home . '/feed/',
        $home . '/feed',
        $home . '/?feed=rss2',
        $home . '/rss.xml',
        $home . '/rss',
    ];
    fwrite(STDERR, "$name: ");
    $found = null;
    foreach ([$browser, $bot] as $client) {
        foreach ($candidates as $u) {
            try {
                $resp = $client->request('GET', $u);
                $code = $resp->getStatusCode();
                if ($code < 200 || $code >= 400) continue;
                $body = (string) $resp->getBody();
                if (isFeedXml($body)) {
                    $found = ['feed_url' => $u, 'http' => $code];
                    break 2;
                }
            } catch (TransferException) {}
        }
    }
    $results[] = ['name' => $name, 'homepage' => $home, 'tier' => $tier, 'feed' => $found];
    fwrite(STDERR, $found ? ("OK " . $found['feed_url'] . "\n") : "ainda bloqueado\n");
}

file_put_contents('/home/jr/fontes-retry-403.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "\nJSON: /home/jr/fontes-retry-403.json\n");
