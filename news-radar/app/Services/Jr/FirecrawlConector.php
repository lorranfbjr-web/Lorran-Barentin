<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;

/**
 * Conector FIRECRAWL — câmaras SoftCâmaras gated (BLOCO 5, goal 02/07/2026).
 *
 * ESTRATÉGIA PROVADA 02/07 (a única que passa): a LISTAGEM /proposicoes é
 * gated (reCAPTCHA invisível), mas as URLs PROFUNDAS (/tramitacoes/…) abrem
 * com proxy "stealth". Então:
 *   1. POST /v2/search  "site:<host> projeto de lei <ano>"  → descobre URLs
 *      profundas indexadas (barato);
 *   2. POST /v2/scrape  proxy:"stealth" de CADA URL profunda (≈5 créditos) →
 *      parseia a proposição (tipo/nº/ano, ementa, data) → jr_camara_proposicoes.
 * A tentativa anterior (scrape da listagem gated) está em
 * ~/backups/radar-interesse-20260702/parte3-tentativa-errada/ — NÃO repetir.
 * LEGISOFT segue BLOQUEADO (reCAPTCHA visível persiste até no stealth).
 *
 * 💳 METERED: plano free (~1000 créditos). Ledger interno (estimativa: search=2,
 * scrape stealth=5) + teto RÍGIDO imposto pelo comando (default 300). 402/401 =
 * para na hora. NÃO contornamos verificação de bot por conta própria — o
 * Firecrawl é a via comercial sancionada.
 */
class FirecrawlConector
{
    /** Estimativa de custo por operação (créditos) — calibrada no PoC 02/07:
     * 1 search + 3 scrapes stealth = 19 créditos reais (≈6/scrape). */
    public const CUSTO_SEARCH = 2;

    public const CUSTO_SCRAPE_STEALTH = 6;

    private array $cfg;

    private float $pausa;

    private int $creditosGastos = 0;

    private bool $semCredito = false;

    public function __construct(?array $cfg = null)
    {
        $raiz = $cfg ?? config('camara');
        $this->cfg = $raiz['firecrawl'];
        $this->pausa = max(1.0, (float) $raiz['pausa_seg']);
    }

    /** Só opera com a chave no .env E a chave-geral ligada na config. */
    public function disponivel(): bool
    {
        return (bool) $this->cfg['ativo'] && (string) $this->cfg['api_key'] !== '';
    }

    /** Motivo humano do bloqueio (pro comando reportar sem imprimir segredo). */
    public function motivoBloqueio(): string
    {
        if ((string) $this->cfg['api_key'] === '') {
            return 'FIRECRAWL_API_KEY ausente no .env';
        }

        return 'JRCAM_FIRECRAWL_ATIVO=false (chave-geral desligada na config)';
    }

    public function dorme(): void
    {
        usleep((int) ($this->pausa * 1_000_000));
    }

    /** Créditos ESTIMADOS gastos por esta instância (ledger do teto). */
    public function creditosGastos(): int
    {
        return $this->creditosGastos;
    }

    /** true se a API devolveu 402 (crédito acabou) — parar tudo. */
    public function semCredito(): bool
    {
        return $this->semCredito;
    }

    /** Créditos restantes REAIS da conta (GET /v2/team/credit-usage) ou null. */
    public function creditosRestantesReais(): ?int
    {
        try {
            $r = Http::withToken((string) $this->cfg['api_key'])->timeout(20)
                ->get($this->url('/v2/team/credit-usage'));
            if ($r->successful()) {
                return (int) ($r->json('data.remainingCredits') ?? $r->json('data.remaining_credits'));
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Passo 1 — descobre URLs profundas (/tramitacoes/…) da câmara via
     * /v2/search. @return array<int,string> URLs únicas, mais recentes primeiro.
     */
    public function descobrirUrls(array $cam, int $ano, int $limite = 20): array
    {
        $host = parse_url((string) $cam['url_base'], PHP_URL_HOST);
        $q = "site:{$host} projeto de lei {$ano}";

        $r = $this->post('/v2/search', ['query' => $q, 'limit' => $limite]);
        $this->creditosGastos += self::CUSTO_SEARCH;
        if ($r === null) {
            return [];
        }

        $urls = [];
        foreach ((array) ($r['data']['web'] ?? $r['data'] ?? []) as $hit) {
            $u = (string) ($hit['url'] ?? '');
            // só URL PROFUNDA de tramitação/proposição individual (a listagem é gated)
            if ($u !== '' && preg_match('#/(tramitacoes|proposicoes)/.*\d#', $u)) {
                $urls[$u] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * Passo 2 — scrape stealth de UMA URL profunda. Devolve
     * ['markdown'=>…,'html'=>…] ou null. Sem retry agressivo (crédito).
     */
    public function scrapeProfundo(string $url): ?array
    {
        $r = $this->post('/v2/scrape', [
            'url' => $url,
            'formats' => ['markdown', 'html'],
            'onlyMainContent' => false,
            'proxy' => 'stealth',
            'waitFor' => (int) $this->cfg['wait_ms'],
        ], 120);
        $this->creditosGastos += self::CUSTO_SCRAPE_STEALTH;
        if ($r === null) {
            return null;
        }

        return [
            'markdown' => (string) ($r['data']['markdown'] ?? ''),
            'html' => (string) ($r['data']['html'] ?? ''),
        ];
    }

    /**
     * Passo 3 — parseia a página de detalhe (markdown) numa proposição no
     * formato de jr_camara_proposicoes. Devolve null se não achar tipo+nº/ano.
     */
    public function parseDetalhe(string $markdown, string $url, array $cam, int $anoMin): ?array
    {
        $md = trim($markdown);
        if ($md === '' || preg_match('/Bot Verification|Verifica..o de seguran/iu', mb_substr($md, 0, 2000))) {
            return null; // gate ainda na frente
        }

        // "Projeto de Lei Ordinária (ou similar) nº 0068/2026" — 1ª ocorrência forte
        if (! preg_match('/\b(Projeto de (?:Lei(?: Complementar| Ordin[áa]ria)?|Decreto|Resolu[çc][ãa]o)|Mo[çc][ãa]o|Indica[çc][ãa]o|Requerimento|Emenda)[^\n]{0,40}?n?[ºo°.\s]*0*(\d{1,5})\/(\d{4})/iu', $md, $t)) {
            return null;
        }
        $ano = (int) $t[3];
        if ($ano < $anoMin) {
            return null;
        }
        $tipo = trim(preg_replace('/\s+/u', ' ', $t[1]));
        $numero = (int) $t[2];

        // data: preferir a rotulada ("Data ...: dd/mm/aaaa"), senão 1ª dd/mm/aaaa
        $dataPub = null;
        if (preg_match('/Data[^\n:]{0,30}:?\s*\**\s*(\d{2}\/\d{2}\/\d{4})/iu', $md, $d)
            || preg_match('/(\d{2}\/\d{2}\/\d{4})/', $md, $d)) {
            $dataPub = sprintf('%s-%s-%s', substr($d[1], 6, 4), substr($d[1], 3, 2), substr($d[1], 0, 2));
        }

        // ementa — no SoftCâmaras ela vem em CAPS, estilo legislativo ("ALTERA A
        // LEI…", "DISPÕE SOBRE…"), muitas vezes como texto de link markdown.
        // Estratégia: (1) desembrulha links, (2) corta boilerplate do site,
        // (3) prefere frase iniciada em verbo legislativo CAPS; fallback = linha
        // longa não-boilerplate.
        $texto = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $md); // [txt](url) → txt
        $ementa = null;
        if (preg_match('/\b((?:ALTERA|DISP[ÕO]E|INSTITUI|AUTORIZA|DENOMINA|CRIA|ESTABELECE|APROVA|FIXA|CONCEDE|REVOGA|ABRE|RECONHECE|DECLARA|REGULAMENTA|PRO[ÍI]BE|OBRIGA|INCLUI)\b[^\n_]{20,500})/u', $texto, $e)) {
            $ementa = trim(preg_replace('/\s+/u', ' ', $e[1]), " *_");
        } elseif (preg_match('/Ementa[^\n:]{0,10}:?\s*\**\s*\n?\s*([^\n]{20,600})/iu', $texto, $e)) {
            $ementa = trim($e[1], " *_");
        } else {
            foreach (preg_split('/\n+/', $texto) as $linha) {
                $linha = trim(preg_replace('/\s+/u', ' ', strip_tags($linha)), " *#|_");
                if (mb_strlen($linha) > mb_strlen((string) $ementa) && mb_strlen($linha) >= 60
                    && ! preg_match('/^(https?:|!\[|menu|in[íi]cio|acessibilidade)/iu', $linha)
                    && ! preg_match('/Todas as Situa|car[áa]ter apenas informativo|Em Tramita[çc][ãa]o:|Apensada|Pesquisar|Filtrar/iu', $linha)) {
                    $ementa = $linha;
                }
            }
        }

        // autores, se rotulados
        $autores = null;
        if (preg_match('/Autor(?:ia|es)?[^\n:]{0,10}:?\s*\**\s*([^\n]{3,160})/iu', $md, $a)) {
            $autores = trim($a[1], " *");
        }

        // docId da URL (último número do path) — unicidade real é o hash
        $docId = preg_match('/(\d+)\/?$/', parse_url($url, PHP_URL_PATH) ?? '', $i) ? (int) $i[1] : crc32($url);

        return [
            'host' => parse_url((string) $cam['url_base'], PHP_URL_HOST) ?: 'softcamaras',
            'materia_id' => $docId,
            'hash' => sha1("firecrawl:softcamaras:{$cam['cidade']}:{$tipo}:{$numero}/{$ano}"),
            'municipio' => $cam['cidade'],
            'orgao' => 'Câmara Municipal de ' . $cam['cidade'],
            'tipo_sigla' => null,
            'tipo_descricao' => $tipo,
            'complementar' => (bool) preg_match('/complementar/iu', $tipo),
            'numero' => $numero,
            'ano' => $ano,
            'ementa' => $ementa,
            'autores' => $autores,
            'em_tramitacao' => true,
            'data_pub' => $dataPub, // Recencia::sanitizar aplica o clamp no ingest
            'titulo' => "{$tipo} nº {$numero}/{$ano}",
            'url_fonte' => $url,
            'url_pdf' => null,
            'texto_bruto' => mb_substr($md, 0, 4000),
        ];
    }

    // ───────────────────────── HTTP ─────────────────────────

    /** POST autenticado; null em erro. 402 (sem crédito) arma o kill switch. */
    private function post(string $path, array $body, int $timeout = 60): ?array
    {
        try {
            $r = Http::withToken((string) $this->cfg['api_key'])
                ->timeout($timeout)
                ->post($this->url($path), $body);
            $j = $r->json();
            if ($r->successful() && ($j['success'] ?? false)) {
                return $j;
            }
            if ($r->status() === 402) {
                $this->semCredito = true; // crédito ACABOU — parar tudo
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** api_base pode vir com /v1 histórico — normaliza pra raiz e monta o path. */
    private function url(string $path): string
    {
        $base = preg_replace('#/v\d+/?$#', '', rtrim((string) $this->cfg['api_base'], '/'));

        return $base . $path;
    }
}
