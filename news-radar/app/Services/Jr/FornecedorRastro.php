<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\DB;

/**
 * Radar Cívico FASE 8 — rastro de fornecedor CROSS-FONTE (best-effort).
 *
 * Coleta nomes de EMPRESA das fontes do radar e cruza: a mesma empresa aparecendo
 * em ≥2 municípios e/ou ≥2 fontes vira um "rastro" (flag + filtro). NÃO inventa
 * dado: só conta o que foi extraído por heurística (sufixo societário LTDA/EIRELI/
 * ME/S.A/EPP). Cobertura é fraca de propósito (o snippet do DOM nem sempre traz o
 * vencedor; câmara não tem fornecedor; MPSC/TCE citam empresa esparsamente) — por
 * isso é SINAL pra apurar, NUNCA prova.
 *
 *   DOM  → coluna fornecedor (vencedor de licitação)
 *   MPSC → partes (empresa investigada/parte)
 *   TCE  → interessado/responsável/assunto (empresa no processo de contas)
 *   Câmara → (sem fornecedor: legislativo)
 */
class FornecedorRastro
{
    /** Regex de razão social com sufixo societário. */
    private const RE_EMPRESA = '/\b([A-ZÀ-Ú][A-Za-zÀ-ú0-9 &.\'\-]{3,60}?(?:LTDA|EIRELI|S\/A|S\.A|EPP|MEI)\b\.?)/u';

    /**
     * @return array{empresas:array<int,array>, cobertura:array}
     */
    public function consolidar(): array
    {
        // empresaNorm => ['nome'=>display, 'municipios'=>set, 'fontes'=>set, 'ocorrencias'=>int]
        $mapa = [];

        $add = function (string $bruto, ?string $municipio, string $fonte) use (&$mapa) {
            $nome = $this->extrair($bruto);
            if ($nome === null) {
                return;
            }
            $k = $this->normalizar($nome);
            if ($k === '' || mb_strlen($k) < 4) {
                return;
            }
            if (! isset($mapa[$k])) {
                $mapa[$k] = ['nome' => $nome, 'municipios' => [], 'fontes' => [], 'ocorrencias' => 0];
            }
            $mapa[$k]['ocorrencias']++;
            if ($municipio) {
                $mapa[$k]['municipios'][$municipio] = true;
            }
            $mapa[$k]['fontes'][$fonte] = true;
        };

        // DOM — fornecedor já isolado
        foreach (DB::table('jr_dom_atos')->whereNotNull('fornecedor')->where('fornecedor', '!=', '')
            ->get(['fornecedor', 'municipio']) as $r) {
            $add($r->fornecedor, $r->municipio, 'dom');
        }
        // MPSC — partes
        foreach (DB::table('jr_mpsc_extratos')->whereNotNull('partes')
            ->get(['partes', 'municipio']) as $r) {
            $add($r->partes, $r->municipio, 'mpsc');
        }
        // TCE — interessado/responsável/assunto
        foreach (DB::table('jr_tce_decisoes')
            ->get(['interessado', 'responsavel', 'assunto', 'municipio']) as $r) {
            $add(trim(($r->interessado ?? '') . ' ' . ($r->responsavel ?? '') . ' ' . ($r->assunto ?? '')), $r->municipio, 'tce');
        }

        $empresas = [];
        foreach ($mapa as $k => $v) {
            $nMun = count($v['municipios']);
            $nFontes = count($v['fontes']);
            // rastro = recorrência: ≥2 municípios OU ≥2 fontes
            if ($nMun >= 2 || $nFontes >= 2) {
                $empresas[] = [
                    'empresa' => $v['nome'],
                    'municipios' => array_keys($v['municipios']),
                    'n_municipios' => $nMun,
                    'fontes' => array_keys($v['fontes']),
                    'n_fontes' => $nFontes,
                    'ocorrencias' => $v['ocorrencias'],
                ];
            }
        }
        usort($empresas, fn ($a, $b) => ($b['n_fontes'] <=> $a['n_fontes']) ?: ($b['n_municipios'] <=> $a['n_municipios']));

        return [
            'empresas' => $empresas,
            'cobertura' => $this->cobertura(),
        ];
    }

    private function extrair(string $texto): ?string
    {
        if (preg_match(self::RE_EMPRESA, $texto, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtoupper(trim($s));
        $s = preg_replace('/\b(LTDA|EIRELI|S\/A|S\.A|EPP|MEI|ME)\b\.?/u', '', $s);
        $s = preg_replace('/[\.,\-\/]/', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Cobertura honesta por fonte (quantos têm empresa extraível). */
    private function cobertura(): array
    {
        $dom = DB::table('jr_dom_atos')->count();
        $domF = DB::table('jr_dom_atos')->whereNotNull('fornecedor')->where('fornecedor', '!=', '')->count();
        $mpsc = DB::table('jr_mpsc_extratos')->count();
        $mpscF = DB::table('jr_mpsc_extratos')->where('partes', 'like', '%LTDA%')
            ->orWhere('partes', 'like', '%EIRELI%')->orWhere('partes', 'like', '%S/A%')->count();
        $tce = DB::table('jr_tce_decisoes')->count();

        return [
            'dom' => ['total' => $dom, 'com_empresa' => $domF],
            'mpsc' => ['total' => $mpsc, 'com_empresa' => $mpscF],
            'tce' => ['total' => $tce],
        ];
    }
}
