<?php

namespace App\Services\Jr;

/**
 * Régua quente/frio compartilhada — UMA verdade só, usada pelos dois caminhos:
 *  - WhatsApp (JrLinkExtract: markdown do trafilatura/Jina)
 *  - feeds NewsRadar (jrlink:bridge-news: colunas de news_items)
 *
 * Input neutro: {url, titulo, corpo, categories[]}. Quem chama adapta a fonte
 * para esses 4 campos. Regras/listas em config/jrlink.php.
 *
 * NÃO contém I/O nem DB — só classificação pura.
 */
class PautaClassifier
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? config('jrlink');
    }

    // ───────────────────────── host / categoria ─────────────────────────

    /**
     * Host resolvido (final): prioriza o `url:`/`hostname:` do frontmatter do
     * trafilatura ou o `URL Source:` do Jina (destino do encurtador); cai pro host
     * da URL original. Sempre sem "www.". (Caminho WhatsApp.)
     */
    public function resolveHost(?string $url, ?string $markdown): ?string
    {
        $md = (string) $markdown;

        if (preg_match('/^url:\s*(\S+)/mi', $md, $m)) {
            if ($h = $this->hostDe($m[1])) {
                return $h;
            }
        }
        if (preg_match('/^hostname:\s*(\S+)/mi', $md, $m)) {
            $h = $this->limpaHost($m[1]);
            if ($h !== '') {
                return $h;
            }
        }
        if (preg_match('/^URL Source:\s*(\S+)/mi', $md, $m)) {
            if ($h = $this->hostDe($m[1])) {
                return $h;
            }
        }

        return $this->hostDe((string) $url);
    }

    /** Host direto da URL (feeds já trazem URL final — sem encurtador). */
    public function hostFromUrl(?string $url): ?string
    {
        return $this->hostDe((string) $url);
    }

    public function hostDe(string $url): ?string
    {
        $h = parse_url($url, PHP_URL_HOST);
        if (! $h) {
            return null;
        }

        return $this->limpaHost($h);
    }

    public function limpaHost(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = preg_replace('/^www\d*\./', '', $h);

        return rtrim($h, '.');
    }

    /** host casa o domínio D se host == D ou termina em ".D". */
    public function hostCasa(string $host, array $dominios): bool
    {
        foreach ($dominios as $d) {
            $d = mb_strtolower($d);
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }

        return false;
    }

    /** Categoria pelo host resolvido: proprio -> social -> primaria -> concorrente -> outro. */
    public function categoria(?string $host): string
    {
        if (! $host) {
            return 'outro';
        }
        if ($this->hostCasa($host, $this->cfg['proprio'] ?? [])) {
            return 'proprio';
        }
        if ($this->hostCasa($host, $this->cfg['social'] ?? [])) {
            return 'social';
        }
        foreach (($this->cfg['gov_suffixes'] ?? []) as $suf) {
            if (str_ends_with($host, mb_strtolower($suf))) {
                return 'primaria';
            }
        }
        foreach (($this->cfg['gov_signals'] ?? []) as $sig) {
            if (str_contains($host, mb_strtolower($sig))) {
                return 'primaria';
            }
        }
        if ($this->hostCasa($host, $this->cfg['concorrente'] ?? [])) {
            return 'concorrente';
        }

        return 'outro';
    }

    // ───────────────────────── gate de status ─────────────────────────

    /**
     * Gate honesto a partir do CORPO já limpo.
     *  - social: nunca "ok" — título com conteúdo (legenda) = "parcial"; senão "vazio".
     *  - muro de login / boilerplate: "parcial" (tem título) ou "vazio".
     *  - corpo real de matéria (>= min_corpo_ok): "ok".
     */
    public function gateStatus(string $categoria, ?string $titulo, string $corpo): string
    {
        $temTitulo = $this->tituloUtil($titulo);

        if ($categoria === 'social') {
            return $temTitulo ? 'parcial' : 'vazio';
        }
        if ($this->ehMuro($corpo)) {
            return $temTitulo ? 'parcial' : 'vazio';
        }
        if (mb_strlen(trim($corpo)) >= (int) ($this->cfg['min_corpo_ok'] ?? 300)) {
            return 'ok';
        }

        return $temTitulo ? 'parcial' : 'vazio';
    }

    /** Remove frontmatter YAML (trafilatura) e cabeçalho do Jina, deixa só o corpo. */
    public function corpoFromMarkdown(?string $markdown): string
    {
        $md = trim((string) $markdown);
        if ($md === '') {
            return '';
        }
        if (str_starts_with($md, '---')) {
            $parts = preg_split('/^---\s*$/m', $md, 3);
            if (is_array($parts) && count($parts) >= 3) {
                $md = trim($parts[2]);
            }
        }
        if (preg_match('/Markdown Content:\s*(.*)$/s', $md, $m)) {
            $md = trim($m[1]);
        }
        $md = preg_replace('/^(Title|URL Source|Published Time|Markdown Content):.*$/mi', '', $md);

        return trim((string) $md);
    }

    private function ehMuro(string $corpo): bool
    {
        $c = mb_strtolower($corpo);
        foreach (($this->cfg['walls'] ?? []) as $w) {
            if (str_contains($c, mb_strtolower($w))) {
                return true;
            }
        }

        return false;
    }

    private function tituloUtil(?string $titulo): bool
    {
        $t = mb_strtolower(trim((string) $titulo));
        if (mb_strlen($t) <= 3) {
            return false;
        }

        return ! in_array($t, $this->cfg['generic_titles'] ?? [], true);
    }

    // ───────────────────────── quente / frio ─────────────────────────

    /**
     * Classifica a pauta em dois eixos (config/jrlink.php > reguas), do CORPO limpo
     * + categories já extraídas. Região detectada pelo CONTEÚDO (título + corpo).
     *
     * @param  array  $categories  categorias da fonte (frontmatter ou coluna), minúsculas
     * @return array{0:?string,1:?string,2:int} [eixo, temperatura, score]
     */
    public function temperatura(string $categoria, ?string $titulo, string $corpo, array $categories = [], ?string $url = null, ?string $reguaKey = null): array
    {
        if (! in_array($categoria, ['primaria', 'concorrente'], true)) {
            return ['-', null, 0];
        }

        if ($this->ehHomeOuGenerico($url, $titulo)) {
            return ['-', null, 0];
        }

        // reguaKey permite régua específica por origem (ex.: 'concorrente_feed' p/ a
        // ponte) sem alterar a régua do WhatsApp. Default = a própria categoria.
        $regua = $this->cfg['reguas'][$reguaKey ?? $categoria] ?? ($this->cfg['reguas'][$categoria] ?? []);
        $texto = mb_strtolower(trim(($titulo ?? '') . ' ' . $corpo));
        $cats = array_map('mb_strtolower', $categories);
        $tituloTxt = mb_strtolower(trim((string) $titulo));

        // Colunismo / horóscopo / opinião = NÃO-PAUTA. Sinal principal = categories;
        // complemento = título. Nunca por palavra solta no corpo.
        $col = $this->cfg['colunismo'] ?? [];
        if ((bool) array_intersect($cats, array_map('mb_strtolower', $col['categories'] ?? []))
            || $this->contemAlgum($tituloTxt, $col['termos_titulo'] ?? [])) {
            return ['-', null, 0];
        }

        $cidadeHit = $this->contemAlgum($texto, $this->cfg['regiao']['cidades'] ?? []);
        $estadoHit = $this->contemAlgum($texto, $this->cfg['regiao']['estado'] ?? []);
        $ganchoHit = $this->contemAlgum($texto, $this->cfg['temas']['gancho_top']['termos'] ?? []);
        $utilHit = $this->contemAlgum($texto, $this->cfg['temas']['utilidade']['termos'] ?? []);
        $temaHit = $this->contemAlgum($texto, $this->cfg['temas']['tema_leve']['termos'] ?? []);

        $score = (int) ($regua['base'] ?? 0);
        if ($cidadeHit) {
            $score += (int) ($regua['peso_regiao_cidade'] ?? 0);
        } elseif ($estadoHit) {
            $score += (int) ($regua['peso_regiao_estado'] ?? 0);
        }
        if ($ganchoHit) {
            $score += (int) ($this->cfg['temas']['gancho_top']['peso'] ?? 0);
        }
        if ($utilHit) {
            $score += (int) ($this->cfg['temas']['utilidade']['peso'] ?? 0);
        }
        if ($temaHit) {
            $score += (int) ($this->cfg['temas']['tema_leve']['peso'] ?? 0);
        }

        $ganchoForte = $ganchoHit || $utilHit;

        // Rotina/clima decidida pelo TÍTULO (+ categories); previsão pura morre,
        // matéria com gancho/utilidade no título escapa.
        $rotina = $this->cfg['rotina_penalty'] ?? [];
        $rotinaTitulo = $this->contemAlgum($tituloTxt, $rotina['termos'] ?? [])
            || (bool) array_intersect($cats, array_map('mb_strtolower', $rotina['categories'] ?? []));
        $hookTitulo = $this->contemAlgum($tituloTxt, $this->cfg['temas']['gancho_top']['termos'] ?? [])
            || $this->contemAlgum($tituloTxt, $this->cfg['temas']['utilidade']['termos'] ?? []);
        if ($rotinaTitulo && ! $hookTitulo) {
            $score = max(0, $score - (int) ($rotina['peso'] ?? 10));

            return [$categoria, 'frio', $score];
        }

        $okRequisitos = true;
        if (($regua['exige_regiao'] ?? false) && ! ($cidadeHit || $estadoHit)) {
            $okRequisitos = false;
        }
        if (($regua['exige_cidade'] ?? false) && ! $cidadeHit) {
            $okRequisitos = false; // feed: só CIDADE conta (SC genérico não basta)
        }
        if (($regua['exige_gancho'] ?? false) && ! $ganchoForte) {
            $okRequisitos = false;
        }

        $quente = $okRequisitos && $score >= (int) ($regua['corte_quente'] ?? 999);

        return [$categoria, $quente ? 'quente' : 'frio', $score];
    }

    public function ehHomeOuGenerico(?string $url, ?string $titulo): bool
    {
        if ($url) {
            $path = rtrim(mb_strtolower((string) parse_url($url, PHP_URL_PATH)), '/');
            $homes = array_map(fn ($p) => rtrim(mb_strtolower($p), '/'), $this->cfg['home_paths'] ?? ['', '/']);
            if (in_array($path, $homes, true)) {
                return true;
            }
        }
        $t = mb_strtolower(trim((string) $titulo));
        if ($t !== '' && in_array($t, $this->cfg['generic_titles'] ?? [], true)) {
            return true;
        }

        return false;
    }

    /** Categorias do frontmatter trafilatura (linha "categories: [...]"), minúsculas. */
    public function categoriesFromMarkdown(?string $markdown): array
    {
        if (! preg_match('/^categories:\s*(.+)$/mi', (string) $markdown, $m)) {
            return [];
        }
        preg_match_all("/'([^']+)'|\"([^\"]+)\"|([^\[\],\s][^,\]]*)/u", $m[1], $mm);
        $out = [];
        foreach (array_merge($mm[1], $mm[2], $mm[3]) as $c) {
            $c = mb_strtolower(trim($c));
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }

    public function contemAlgum(string $texto, array $termos): bool
    {
        foreach ($termos as $t) {
            if ($t !== '' && str_contains($texto, mb_strtolower($t))) {
                return true;
            }
        }

        return false;
    }

    // ───────────────────────── dedup / normalização ─────────────────────────

    /**
     * Normaliza a URL p/ dedup — UMA verdade: minúsculo no esquema+host, http->https,
     * tira barra final, remove tracking (utm_*, igsh, fbclid, mode, mc_cid, _ga, …)
     * e fragmento. Lista unificada em config/jrlink.php > tracking_params.
     */
    public function normalizeUrl(string $url): string
    {
        $p = parse_url(trim($url));
        if ($p === false || empty($p['host'])) {
            return mb_strtolower(rtrim(trim($url), '/'));
        }

        $scheme = mb_strtolower($p['scheme'] ?? 'https');
        if ($scheme === 'http') {
            $scheme = 'https';
        }
        $host = $this->limpaHost($p['host']);
        $port = isset($p['port']) && ! in_array((int) $p['port'], [80, 443], true) ? ':' . $p['port'] : '';
        $path = rtrim($p['path'] ?? '', '/');

        $query = '';
        if (! empty($p['query'])) {
            parse_str($p['query'], $q);
            $drop = array_map('mb_strtolower', $this->cfg['tracking_params'] ?? []);
            $kept = [];
            foreach ($q as $k => $v) {
                $lk = mb_strtolower($k);
                if (str_starts_with($lk, 'utm_') || in_array($lk, $drop, true)) {
                    continue;
                }
                $kept[$k] = $v;
            }
            if ($kept) {
                ksort($kept);
                $query = '?' . http_build_query($kept);
            }
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /** Hash canônico p/ colapsar cross-source: sha256 da URL normalizada. */
    public function urlHashNorm(string $url): string
    {
        return hash('sha256', $this->normalizeUrl($url));
    }
}
