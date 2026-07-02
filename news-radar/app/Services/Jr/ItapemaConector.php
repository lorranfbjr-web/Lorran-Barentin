<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Process;

/**
 * Conector ITAPEMA (elegis2) — raspa a lista de PROJETOS da Câmara de Itapema
 * em site.itapema.sc.leg.br/elegis2/lista-projeto (HTML aberto, server-rendered,
 * sem gate — sonda 02/07/2026). lista-propositura (Indicação/Requerimento) fica
 * FORA (ruído, mesma régua dos tipos_relevantes do SAPL).
 *
 * Estrutura provada na sonda:
 *  - listagem paginada (?pagina=N, 30/pág): tipo+nº/ano, autor, situação e o
 *    cod_proposicao no link de detalhe;
 *  - detalhe (detalhe-proposicao/cod_proposicao/<cod>): Data de Criação;
 *  - EMENTA só existe no PDF da íntegra (/index/pdf/...tipo/projeto → redirect
 *    pro PDF real) — extraída da 1ª página via PyMuPDF (venv scripts/pdf-venv).
 *
 * READ-ONLY, educado (>=1s entre requests). ISOLADO: só jr_camara_proposicoes.
 */
class ItapemaConector
{
    private string $base;

    private string $ua;

    private float $pausa;

    private string $python;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? config('camara');
        $this->base = rtrim((string) $cfg['itapema']['base'], '/');
        $this->ua = (string) $cfg['user_agent'];
        $this->pausa = max(1.0, (float) $cfg['pausa_seg']);
        $this->python = (string) $cfg['itapema']['python'];
    }

    public function dorme(): void
    {
        usleep((int) ($this->pausa * 1_000_000));
    }

    /**
     * Linhas da listagem de projetos (uma página).
     *
     * @return array<int,array{cod:int,titulo:string,tipo:string,numero:int,ano:int,autor:?string,situacao:?string}>
     */
    public function listar(int $pagina): array
    {
        $html = $this->curl("{$this->base}/elegis2/lista-projeto" . ($pagina > 1 ? "?pagina={$pagina}" : ''));
        if ($html === null) {
            throw new \RuntimeException("Itapema lista-projeto p{$pagina}: sem resposta");
        }

        $out = [];
        foreach (preg_split('/<tr>/', $html) as $tr) {
            if (! str_contains($tr, 'detalhe-proposicao')) {
                continue;
            }
            // 1º link = "Tipo N/AAAA", 2º = autor
            if (! preg_match_all("/cod_proposicao\/(\d+)'>\s*([^<]+?)\s*</su", $tr, $ls, PREG_SET_ORDER)) {
                continue;
            }
            $cod = (int) $ls[0][1];
            $titulo = trim(preg_replace('/\s+/u', ' ', html_entity_decode($ls[0][2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (! preg_match('/^(.*?)\s+(\d+)\/(\d{4})$/u', $titulo, $t)) {
                continue;
            }
            $autor = isset($ls[1][2])
                ? trim(preg_replace('/\s+/u', ' ', html_entity_decode($ls[1][2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : null;
            $sit = null;
            if (preg_match("/data-title='Situação'[^>]*>(.*?)<\/td>/su", $tr, $s)) {
                $sit = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: null;
            }
            $out[] = [
                'cod' => $cod,
                'titulo' => $titulo,
                'tipo' => trim($t[1]),
                'numero' => (int) $t[2],
                'ano' => (int) $t[3],
                'autor' => $autor,
                'situacao' => $sit,
            ];
        }

        return $out;
    }

    /** Data de Criação (Y-m-d) da proposição, do detalhe. */
    public function dataCriacao(int $cod): ?string
    {
        $html = $this->curl("{$this->base}/elegis2/detalhe-proposicao/cod_proposicao/{$cod}");
        if ($html && preg_match('/Data de Criação::?.*?(\d{2})\/(\d{2})\/(\d{4})/su', $html, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }

    /**
     * Baixa o PDF da íntegra e extrai [ementa, texto, urlPdfFinal] da 1ª página.
     *
     * @return array{0:?string,1:?string,2:?string}
     */
    public function ementaDoPdf(int $cod): array
    {
        $url = "{$this->base}/index/pdf/module/elegis2/lmargin/19/cod_proposicao/{$cod}/tipo/projeto";
        $tmp = tempnam(sys_get_temp_dir(), 'elegis2_');
        try {
            $r = Process::timeout(70)->run([
                'curl', '-sL', '--max-time', '60', '-A', $this->ua, '-o', $tmp,
                '-w', '%{url_effective}', $url,
            ]);
            $urlFinal = trim((string) $r->output()) ?: null;
            if (! is_file($tmp) || filesize($tmp) < 500) {
                return [null, null, $urlFinal];
            }
            $py = Process::timeout(60)->run([
                $this->python, '-c',
                "import sys, fitz; d = fitz.open(sys.argv[1]); print(d[0].get_text())",
                $tmp,
            ]);
            if (! $py->successful()) {
                return [null, null, $urlFinal];
            }
            $texto = trim(preg_replace('/\s+/u', ' ', (string) $py->output()));

            // ementa = trecho entre o título ("... N 446/2026") e o "Art. 1º"
            $ementa = null;
            if (preg_match('/N[ºo°]?\s*\d+\/\d{4}\s*(.*?)(?:\bArt\.?\s*1|\bA Câmara|\bO Povo|$)/su', $texto, $m)) {
                $ementa = trim(mb_substr(trim($m[1]), 0, 400)) ?: null;
            }

            return [$ementa, mb_substr($texto, 0, 1200) ?: null, $urlFinal];
        } finally {
            @unlink($tmp);
        }
    }

    private function curl(string $url): ?string
    {
        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            try {
                $r = Process::timeout(60)->run(['curl', '-s', '--max-time', '50', '-A', $this->ua, $url]);
                $body = (string) $r->output();
                if ($r->successful() && $body !== '') {
                    return $body;
                }
            } catch (\Throwable $e) {
                // retry
            }
            sleep(2);
        }

        return null;
    }
}
