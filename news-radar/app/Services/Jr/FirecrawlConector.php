<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;

/**
 * Conector FIRECRAWL — câmaras SoftCâmaras + LEGISOFT (Fase 2, goal 02/07/2026).
 *
 * Os 2 grupos cobrem 30 cidades tier1/tier2 (Tijucas, Canelinha, Nova Trento,
 * Bombinhas, Navegantes, Brusque, Floripa, São José, Palhoça, Biguaçu, GCR,
 * Camboriú, BC, Piçarras, Itajaí, Criciúma, Gaspar…) atrás de reCAPTCHA
 * invisível (lsrecaptcha no SoftCâmaras, /captcha no LEGISOFT) que bloqueia
 * curl de QUALQUER IP. A via sancionada pelo Lorran é o serviço comercial
 * Firecrawl (api.firecrawl.dev), que renderiza a página publicada e devolve
 * HTML/markdown — NÃO contornamos verificação de bot por conta própria.
 *
 * ESTADO 02/07/2026: BLOQUEADO — sem FIRECRAWL_API_KEY no .env (falta o Lorran
 * contratar e colar a chave). Este conector fica PRONTO-PRA-PLUGAR:
 *   1. colar FIRECRAWL_API_KEY=fc-... no .env
 *   2. JRCAM_FIRECRAWL_ATIVO=true
 *   3. PoC: php artisan jr:firecrawl-ingest --poc --dry  (Canelinha + Tijucas)
 *   4. validar os parsers contra o HTML real (escritos hoje só com a EVIDÊNCIA
 *      da sonda — padrões de URL indexados pelo Google — porque as páginas são
 *      inacessíveis sem o serviço; ajustar regex se o markup divergir)
 *   5. descomentar o schedule em routes/console.php (1 request/câmara/dia)
 *
 * READ-ONLY e educado (cadência no schedule, não em rajada). ISOLADO: só
 * alimenta jr_camara_proposicoes — o CamaraScorer existente pontua sem mudança.
 */
class FirecrawlConector
{
    private array $cfg;

    private float $pausa;

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
            return 'FIRECRAWL_API_KEY ausente no .env (falta contratar o Firecrawl e colar a chave)';
        }

        return 'JRCAM_FIRECRAWL_ATIVO=false (chave-geral desligada na config)';
    }

    public function dorme(): void
    {
        usleep((int) ($this->pausa * 1_000_000));
    }

    /**
     * Renderiza $url via Firecrawl /v1/scrape e devolve ['html'=>…,'markdown'=>…]
     * ou null. waitFor dá tempo do reCAPTCHA invisível resolver e a listagem
     * hidratar. Retry 1x (crédito é cobrado por request — não insistir).
     */
    public function scrape(string $url): ?array
    {
        $endpoint = rtrim((string) $this->cfg['api_base'], '/') . '/scrape';
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            try {
                $r = Http::withToken((string) $this->cfg['api_key'])
                    ->timeout(90)
                    ->post($endpoint, [
                        'url' => $url,
                        'formats' => ['html', 'markdown'],
                        'onlyMainContent' => false,
                        'waitFor' => (int) $this->cfg['wait_ms'],
                    ]);
                $j = $r->json();
                if ($r->successful() && ($j['success'] ?? false)) {
                    return [
                        'html' => (string) ($j['data']['html'] ?? ''),
                        'markdown' => (string) ($j['data']['markdown'] ?? ''),
                    ];
                }
                // 402 = sem crédito; 401 = chave inválida — insistir não resolve
                if (in_array($r->status(), [401, 402], true)) {
                    return null;
                }
            } catch (\Throwable $e) {
                // cai no retry
            }
            sleep(3);
        }

        return null;
    }

    /**
     * Proposições da câmara $cam (entrada de config.camara.firecrawl.cidades),
     * ano >= $anoMin, roteando o parser pela plataforma.
     *
     * @return array<int,array> itens no formato de jr_camara_proposicoes
     */
    public function proposicoes(array $cam, int $anoMin): array
    {
        $r = $this->scrape((string) $cam['url']);
        if ($r === null || $r['html'] === '') {
            throw new \RuntimeException("Firecrawl {$cam['cidade']}: sem HTML utilizável");
        }
        // gate ainda na frente = o render não passou (crédito/config) — não parsear lixo
        if (preg_match('/Bot Verification|Verifica..o de seguran/iu', mb_substr($r['html'], 0, 4000))) {
            throw new \RuntimeException("Firecrawl {$cam['cidade']}: página ainda é o gate (render não passou)");
        }

        return match ($cam['plataforma']) {
            'softcamaras' => $this->parseSoftcamaras($r['html'], $cam, $anoMin),
            'legisoft' => $this->parseLegisoft($r['html'], $cam, $anoMin),
            default => throw new \RuntimeException("Firecrawl: plataforma desconhecida '{$cam['plataforma']}'"),
        };
    }

    /**
     * SOFTCAMARAS — evidência da sonda 02/07: listagem em /proposicoes, itens
     * linkando /proposicoes/<Categoria>/<ano>/<pag>/<autor>/<id> e/ou
     * /tramitacoes/<x>/<id>, com texto tipo "Projeto de Lei Ordinária
     * Executivo nº 0068/2026" (indexado pelo Google em Canelinha/Camboriú).
     * VALIDAR contra o HTML real no PoC — regex tolerante de propósito.
     *
     * @return array<int,array>
     */
    private function parseSoftcamaras(string $html, array $cam, int $anoMin): array
    {
        $out = [];
        $rx = '/<a[^>]+href="[^"]*\/(?:tramitacoes|proposicoes)\/[^"]*?(\d+)"[^>]*>(.*?)<\/a>/su';
        if (! preg_match_all($rx, $html, $ms, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($ms as $m) {
            [, $docId, $rotuloHtml] = $m;
            $rotulo = trim(preg_replace('/\s+/u', ' ',
                html_entity_decode(strip_tags($rotuloHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            // "Projeto de Lei Ordinária Executivo nº 0068/2026" → tipo + nº/ano
            if (! preg_match('/^(.*?)\s*(?:n[ºo°.]*\s*)?0*(\d+)\/(\d{4})\b/iu', $rotulo, $t)) {
                continue;
            }
            $ano = (int) $t[3];
            if ($ano < $anoMin) {
                continue;
            }
            $tipo = trim(preg_replace('/\s+(?:Executivo|Legislativo)$/iu', '', trim($t[1])));
            $out[$docId] = $this->item($cam, (int) $docId, $tipo, (int) $t[2], $ano, $rotulo,
                rtrim((string) $cam['url_base'], '/') . "/tramitacoes/1/{$docId}");
        }

        return array_values($out); // keyed por docId = dedup na própria página
    }

    /**
     * LEGISOFT — evidência da sonda 02/07: documentos em /documento/<slug>-<id>
     * (Blumenau usa digital.* com filtros /documentos/tipo:…/ano:…). Rótulo do
     * anchor carrega o título ("Projeto de Lei Nº 123/2026 — ementa…").
     * VALIDAR contra o HTML real no PoC.
     *
     * @return array<int,array>
     */
    private function parseLegisoft(string $html, array $cam, int $anoMin): array
    {
        $out = [];
        $rx = '/<a[^>]+href="[^"]*\/documentos?\/[^"]*?-?(\d+)\/?"[^>]*>(.*?)<\/a>/su';
        if (! preg_match_all($rx, $html, $ms, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($ms as $m) {
            [, $docId, $rotuloHtml] = $m;
            $rotulo = trim(preg_replace('/\s+/u', ' ',
                html_entity_decode(strip_tags($rotuloHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (! preg_match('/^(.*?)\s*(?:n[ºo°.]*\s*)?0*(\d+)\/(\d{4})\b\s*[—–:-]?\s*(.*)$/iu', $rotulo, $t)) {
                continue;
            }
            $ano = (int) $t[3];
            if ($ano < $anoMin) {
                continue;
            }
            $out[$docId] = $this->item($cam, (int) $docId, trim($t[1]), (int) $t[2], $ano, $rotulo,
                rtrim((string) $cam['url_base'], '/') . "/documento/{$docId}",
                trim($t[4]) ?: null);
        }

        return array_values($out);
    }

    /** Item no formato de jr_camara_proposicoes (mesmo contrato dos irmãos). */
    private function item(array $cam, int $docId, string $tipo, int $numero, int $ano,
        string $titulo, string $urlFonte, ?string $ementa = null): array
    {
        return [
            'host' => parse_url((string) $cam['url_base'], PHP_URL_HOST) ?: $cam['plataforma'],
            'materia_id' => $docId,
            'hash' => sha1("firecrawl:{$cam['plataforma']}:{$cam['cidade']}:{$docId}"),
            'municipio' => $cam['cidade'],
            'orgao' => 'Câmara Municipal de ' . $cam['cidade'],
            'tipo_sigla' => null,
            'tipo_descricao' => $tipo,
            'complementar' => (bool) preg_match('/complementar/iu', $tipo),
            'numero' => $numero,
            'ano' => $ano,
            'ementa' => $ementa,
            'autores' => null,
            'em_tramitacao' => true, // listagem corrente; refinamento fica pro PoC
            'data_pub' => null,      // data não vem na listagem — detalhe fica pro PoC
            'titulo' => $titulo,
            'url_fonte' => $urlFonte,
            'url_pdf' => null,
            'texto_bruto' => $ementa,
        ];
    }
}
