<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Conector do Diário Oficial dos Municípios de SC (FECAM/CIGA).
 *
 * Usa a BUSCA PÚBLICA estruturada (Solr via GET, sem auth/captcha):
 *   /?r=site/index&q=+categoria:"X"+data:[INI TO FIM]&AtoASolrDocument_page=N
 *
 * A própria página de resultados já traz, por ato: título, número (ato_id),
 * data, categoria, ÓRGÃO/MUNICÍPIO, link do PDF assinado e um SNIPPET textual
 * com objeto/vencedor/valores. Não precisa baixar PDF pra esta PoC.
 *
 * READ-ONLY, educado: User-Agent identificável + pausa entre requisições.
 */
class DomConector
{
    private string $base;

    private string $ua;

    private float $pausa;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? config('dom');
        $this->base = rtrim((string) $cfg['base_url'], '/');
        $this->ua = (string) $cfg['user_agent'];
        $this->pausa = (float) $cfg['pausa_seg'];
    }

    /**
     * Total de publicações que a busca reporta pra (categoria, janela).
     */
    public function total(string $categoria, Carbon $ini, Carbon $fim): int
    {
        $html = $this->fetchPagina($categoria, $ini, $fim, 1);
        if (preg_match('/encontrad[ao]s?\s+([\d.]+)\s+publica/iu', $html, $m)) {
            return (int) str_replace('.', '', $m[1]);
        }

        return 0;
    }

    /**
     * Itera as páginas de uma categoria e devolve os atos parseados (lazy).
     * Para quando acaba o resultado ou atinge $maxPaginas. Pausa entre páginas.
     *
     * @return \Generator<array>
     */
    public function atos(string $categoria, Carbon $ini, Carbon $fim, int $maxPaginas): \Generator
    {
        for ($pagina = 1; $pagina <= $maxPaginas; $pagina++) {
            $html = $this->fetchPagina($categoria, $ini, $fim, $pagina);
            if ($html === '') {
                continue; // fetch falhou (rede) — pula a página, não encerra a categoria
            }
            $itens = $this->parse($html, $categoria);
            if (! $itens) {
                break; // página válida sem resultados = fim real da categoria
            }
            foreach ($itens as $it) {
                yield $it;
            }
            if ($this->pausa > 0) {
                usleep((int) ($this->pausa * 1_000_000));
            }
        }
    }

    /**
     * BUSCA DIRIGIDA (on-demand): query AO VIVO no Solr por município (termo
     * livre, pós-filtrado por precisão no controller), categoria opcional e
     * janela. Mesma listagem parseável do site/index. Devolve atos parseados.
     *
     * Nota: o filtro estrito por entidade (entidade:"X"+codigoEntidade na rota
     * site/portal) é frágil/sessão-dependente — por isso usamos termo livre.
     *
     * @return array<int,array>
     */
    public function buscar(string $municipio, ?string $categoria, Carbon $ini, Carbon $fim, int $maxPaginas = 5): array
    {
        $out = [];
        for ($pagina = 1; $pagina <= $maxPaginas; $pagina++) {
            $html = $this->fetchPagina($categoria, $ini, $fim, $pagina, $municipio);
            if ($html === '') {
                break;
            }
            $itens = $this->parse($html, $categoria ?: '');
            if (! $itens) {
                break;
            }
            foreach ($itens as $it) {
                $out[] = $it;
            }
            if ($this->pausa > 0) {
                usleep((int) ($this->pausa * 1_000_000));
            }
        }

        return $out;
    }

    private function fetchPagina(?string $categoria, Carbon $ini, Carbon $fim, int $pagina, ?string $termo = null): string
    {
        // Termos SEPARADOS POR ESPAÇO (operador default do Solr). NÃO usar "+"
        // literal entre termos: o http_build_query o codifica como %2B (operador
        // MUST do Lucene) e muda a semântica — derruba o total. data:[…] usa o
        // dia seguinte ao fim como teto exclusivo (offset SC ~UTC-3).
        $partes = [];
        if ($categoria) {
            $partes[] = sprintf('categoria:"%s"', $categoria);
        }
        if ($termo) {
            $partes[] = sprintf('"%s"', str_replace('"', '', $termo));
        }
        $partes[] = sprintf('data:[%sT03:00:00Z TO %sT02:59:59Z]', $ini->toDateString(), $fim->copy()->addDay()->toDateString());
        $q = implode(' ', $partes);

        // Resiliente: hiccup de rede não derruba a ingestão inteira (pula a página).
        try {
            $resp = Http::withHeaders(['User-Agent' => $this->ua])
                ->timeout(40)
                ->retry(3, 2000, throw: false)
                ->get($this->base . '/', [
                    'r' => 'site/index',
                    'q' => $q,
                    'AtoASolrDocument_page' => $pagina,
                ]);

            return $resp->ok() ? $resp->body() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Parseia os blocos de resultado da listagem.
     *
     * Estrutura por item:
     *   <h4><a href="/atos/8463343">TÍTULO</a></h4>
     *   <span class="quiet">N.º 8463343 - 29/06/2026 10:50 - <span class="label">..</span>
     *     - Licitações - Prefeitura municipal de Timbó<br></span>
     *   <a href="/?r=site/autopublicacaoAssinado&id=8463343">[Abrir/Salvar Original]</a>
     *   <p>TEXTO…</p>
     *
     * @return array<int,array>
     */
    public function parse(string $html, string $categoria): array
    {
        $out = [];
        // Quebra em blocos por <h4><a href="/atos/ID">
        if (! preg_match_all('#<h4><a href="/atos/(\d+)">(.*?)</a></h4>(.*?)(?=<h4><a href="/atos/\d+">|<div|<ul class="pagination)#is', $html, $blocos, PREG_SET_ORDER)) {
            return $out;
        }

        foreach ($blocos as $b) {
            $atoId = (int) $b[1];
            $titulo = $this->limpa($b[2]);
            $corpo = $b[3];

            // linha "quiet": N.º ID - DD/MM/YYYY HH:MM - <label> - CATEGORIA - ÓRGÃO
            // (termina em "<br></span>"; tem um <span class="label"> aninhado, por
            // isso casamos até o primeiro <br>, não até o primeiro </span>).
            $data = null;
            $orgao = null;
            if (preg_match('#<span class="quiet">(.*?)<br\s*/?>#is', $corpo, $q)) {
                $quiet = $this->limpa($q[1]);
                if (preg_match('#(\d{2}/\d{2}/\d{4})#', $quiet, $dm)) {
                    $data = Carbon::createFromFormat('d/m/Y', $dm[1])->toDateString();
                }
                // órgão = trecho após o último " - "
                $partes = array_map('trim', explode(' - ', $quiet));
                $orgao = end($partes) ?: null;
            }

            // texto: bloco <p>…<p/> (o portal fecha com <p/> auto-fechado, não </p>)
            $texto = '';
            if (preg_match('#<p>(.*?)<p\s*/?>#is', $corpo, $pm)) {
                $texto = $this->limpa($pm[1]);
            }

            $out[] = [
                'ato_id' => $atoId,
                'titulo' => $titulo,
                'orgao' => $orgao,
                'municipio' => $this->municipioDe($orgao),
                'categoria' => $categoria,
                'modalidade' => $this->modalidadeDe($titulo . ' ' . $texto),
                'objeto' => $this->objetoDe($texto, $titulo),
                'objeto_limpo' => DomObjetoLimpo::limpar($texto, $titulo),
                'valor' => $this->valorDe($texto),
                'fornecedor' => $this->fornecedorDe($texto),
                'data_pub' => $data,
                'url_fonte' => $this->base . '/atos/' . $atoId,
                'url_pdf' => $this->base . '/?r=site/autopublicacaoAssinado&id=' . $atoId,
                'texto_bruto' => $texto,
            ];
        }

        return $out;
    }

    // ───────────────────────── parsers de campo ─────────────────────────

    private function limpa(string $s): string
    {
        $s = preg_replace('/<[^>]+>/', ' ', $s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** "Prefeitura municipal de Timbó" -> "Timbó"; tolera Câmara/Fundo/Autarquia. */
    private function municipioDe(?string $orgao): ?string
    {
        if (! $orgao) {
            return null;
        }
        // greedy até o ÚLTIMO " de " (ex.: "Serviço … de Água e Esgoto de Tijucas" -> "Tijucas")
        if (preg_match('/.*\bde\s+(.+)$/iu', $orgao, $m)) {
            return trim($m[1]);
        }

        return $orgao;
    }

    private function modalidadeDe(string $t): ?string
    {
        $t = mb_strtolower($t);
        $mapa = [
            'inexigibilidade' => 'inexigibilidade',
            'dispensa'        => 'dispensa',
            'pregão'          => 'pregão',
            'pregao'          => 'pregão',
            'concorrência'    => 'concorrência',
            'concorrencia'    => 'concorrência',
            'tomada de preço' => 'tomada de preços',
            'leilão'          => 'leilão',
            'leilao'          => 'leilão',
            'chamada pública' => 'chamada pública',
            'chamamento'      => 'chamamento',
            'ata de registro' => 'ata de registro de preços',
            'registro de preç' => 'ata de registro de preços',
            'termo aditivo'   => 'aditivo',
            'aditivo'         => 'aditivo',
            'emergencial'     => 'emergencial',
        ];
        foreach ($mapa as $k => $v) {
            if (str_contains($t, $k)) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Maior valor monetário (BR) no snippet. Aceita com ou sem "R$" — exige o
     * padrão de centavos (,dd) e milhar opcional, o que descarta números de
     * processo/lei/data (ex.: 059/2026, 14.133/2021, 29/06/2026). O snippet é
     * truncado, então valor fica esparso — é esperado (valor não é requisito).
     */
    private function valorDe(string $texto): ?float
    {
        if (! preg_match_all('/(?:R\$\s*)?(\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2})/u', $texto, $m)) {
            return null;
        }
        $max = 0.0;
        foreach ($m[1] as $raw) {
            $n = (float) str_replace(',', '.', str_replace('.', '', $raw));
            $max = max($max, $n);
        }

        // ignora ruído de centavos isolados (ex.: "0,00", "1,00" de referência)
        return $max >= 100 ? $max : null;
    }

    private function fornecedorDe(string $texto): ?string
    {
        // "Vencedor ... NOME LTDA/EIRELI/ME/S.A." ou "NNNNNN - NOME LTDA"
        if (preg_match('/\b\d{4,}\s*[-–]\s*([A-ZÀ-Ú][^,;.\n]{4,70}?(?:LTDA|EIRELI|ME|S\/A|S\.A|MEI|EPP)\b\.?)/u', $texto, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\b([A-ZÀ-Ú][A-ZÀ-Ú0-9 &.\'-]{6,70}?(?:LTDA|EIRELI|S\/A|S\.A)\b\.?)/u', $texto, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function objetoDe(string $texto, string $titulo): ?string
    {
        if (preg_match('/objeto[:\s]+(.{15,260}?)(?:\.\s|valor|vencedor|fornecedor|contratad|R\$|$)/iu', $texto, $m)) {
            return trim($m[1]);
        }

        return mb_substr($texto ?: $titulo, 0, 240) ?: null;
    }
}
