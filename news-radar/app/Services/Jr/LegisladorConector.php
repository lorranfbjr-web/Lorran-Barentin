<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Process;

/**
 * Conector LEGISLADOR WEB (legislador.com.br) — lê os projetos EM TRAMITAÇÃO de
 * câmaras de SC pelo HTML ASP server-rendered (SEM gate; sonda 02/07/2026).
 * UM conector genérico parametrizado por ID cobre as 7 cidades de interesse
 * (Penha=2, Jaraguá do Sul=5, SJB=34, Guabiruba=40, Corupá=79, Ilhota=92,
 * Porto Belo=337). Padrão do SaplConector: timeout+retry, UA honesto, pausa.
 *
 * Peculiaridades do ASP clássico (provadas na sonda):
 *  - responde HTTP 302 "Objeto movido" MAS com a página inteira no CORPO —
 *    NÃO seguir o redirect (seguir = cair em emManutencao e perder o corpo);
 *  - charset Windows-1252 → convertemos pra UTF-8;
 *  - a página WCI=ProjetoTramite lista TODOS os projetos em tramitação (todos
 *    os anos) como cards: título "Projeto de Lei Ordinária (L) 57/2026",
 *    subtítulo "de 16/06/2026", ementa no card-text e espécie numérica no
 *    onclick WinProjetoTXT(id, especie, numero, ano).
 *
 * READ-ONLY e educado. ISOLADO: só alimenta jr_camara_proposicoes.
 */
class LegisladorConector
{
    private string $base;

    private string $ua;

    private float $pausa;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? config('camara');
        $this->base = (string) $cfg['legislador']['base'];
        $this->ua = (string) $cfg['user_agent'];
        $this->pausa = max(1.0, (float) $cfg['pausa_seg']); // trava: >=1 req/s por host
    }

    public function dorme(): void
    {
        usleep((int) ($this->pausa * 1_000_000));
    }

    /**
     * Projetos em tramitação da câmara $camId (ID do Legislador), ano >= $anoMin.
     *
     * @return array<int,array> itens no formato de jr_camara_proposicoes
     */
    public function projetos(int $camId, string $municipio, int $anoMin): array
    {
        $html = $this->fetch(['WCI' => 'ProjetoTramite', 'ID' => $camId]);
        if ($html === null) {
            throw new \RuntimeException("Legislador ID={$camId}: sem resposta utilizável");
        }

        return $this->parseCards($html, $camId, $municipio, $anoMin);
    }

    /**
     * GET sem seguir redirect (o 302 traz o conteúdo no corpo). Via curl-CLI:
     * o servidor fecha o TLS sem close_notify e o OpenSSL 3 do PHP aborta com
     * "unexpected eof" — o curl binário tolera (provado na sonda 02/07).
     */
    private function fetch(array $query): ?string
    {
        $url = $this->base . '?' . http_build_query($query);
        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            try {
                $r = Process::timeout(60)->run([
                    'curl', '-s', '--max-time', '50', '-A', $this->ua, $url,
                ]);
                $body = (string) $r->output();
                // aceita o corpo mesmo com exit!=0 parcial, desde que os cards estejam lá
                if ($body !== '' && str_contains($body, 'card-title')) {
                    return mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
                }
            } catch (\Throwable $e) {
                // cai no retry
            }
            sleep(2);
        }

        return null;
    }

    /** @return array<int,array> */
    private function parseCards(string $html, int $camId, string $municipio, int $anoMin): array
    {
        $orgao = 'Câmara Municipal de ' . $municipio;
        $out = [];

        // um card = título (h5) → data (h6) → ementa (card-text) → WinProjetoTXT(id, especie, nr, ano)
        $rx = '/<h5 class="card-title">([^<]+)<\/h5>\s*'
            . '<h6 class="card-subtitle[^>]*>de\s+(\d{2}\/\d{2}\/\d{4})<\/h6>.*?'
            . '<p class="card-text">(.*?)<\/p>.*?'
            . 'WinProjetoTXT\((\d+),(\d+),(\d+),(\d+)/su';
        if (! preg_match_all($rx, $html, $ms, PREG_SET_ORDER)) {
            return $out;
        }

        foreach ($ms as $m) {
            [, $titulo, $dataBr, $ementaHtml, , $especie, $numero, $ano] = $m;
            $ano = (int) $ano;
            if ($ano < $anoMin) {
                continue;
            }

            // "Projeto de Lei Ordinária (L) 57/2026" → tipo + origem (L/E)
            $titulo = trim(html_entity_decode($titulo, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $origem = null;
            $tipo = $titulo;
            if (preg_match('/^(.*?)\s*\((L|E)\)\s*\d+\/\d+$/u', $titulo, $t)) {
                $tipo = trim($t[1]);
                $origem = $t[2] === 'E' ? 'Executivo' : 'Legislativo';
            } elseif (preg_match('/^(.*?)\s*\d+\/\d+$/u', $titulo, $t)) {
                $tipo = trim($t[1]);
            }

            $ementa = trim(preg_replace('/\s+/u', ' ',
                html_entity_decode(strip_tags($ementaHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $ementa = trim($ementa, " \u{A0}"); // &nbsp; de enchimento no fim

            [$d, $mo, $y] = explode('/', $dataBr);
            $numero = (int) $numero;
            $especie = (int) $especie;

            $out[] = [
                'host' => 'legislador.com.br',
                // id numérico sintético (a UNICIDADE real é o hash abaixo)
                'materia_id' => crc32("{$camId}:{$especie}:{$numero}:{$ano}"),
                'hash' => sha1("legislador:{$camId}:{$especie}:{$numero}/{$ano}"),
                'municipio' => $municipio,
                'orgao' => $orgao,
                'tipo_sigla' => null,
                'tipo_descricao' => $tipo,
                'complementar' => (bool) preg_match('/complementar/iu', $tipo),
                'numero' => $numero,
                'ano' => $ano,
                'ementa' => $ementa ?: null,
                'autores' => $origem ? "Origem: {$origem}" : null,
                'em_tramitacao' => true, // ProjetoTramite só lista em tramitação
                'data_pub' => sprintf('%04d-%02d-%02d', (int) $y, (int) $mo, (int) $d),
                'titulo' => $titulo,
                'url_fonte' => $this->base . "?WCI=ProjetoTexto&ID={$camId}&inEspecie={$especie}&nrProjeto={$numero}&aaProjeto={$ano}",
                'url_pdf' => null,
                'texto_bruto' => $ementa ?: null,
            ];
        }

        return $out;
    }
}
