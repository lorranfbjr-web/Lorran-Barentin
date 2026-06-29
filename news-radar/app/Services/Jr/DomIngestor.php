<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingestor DOM/SC — grava atos de uma janela em jr_dom_atos (OBJ1).
 *
 * Compartilhado pelo FORWARD (jr:dom-ingest, grade /5, incremental e barato com
 * early-stop em vistos consecutivos) e pelo RETROATIVO (jr:dom-retroativo, varre
 * pra trás aos poucos com cursor). Dedup idempotente por hash(ato_id) — re-rodar
 * nunca duplica. ADITIVO e ISOLADO: não toca juiz/radar/captura.
 */
class DomIngestor
{
    public function __construct(private DomConector $conector)
    {
    }

    /**
     * Varre as categorias na janela [ini, fim] e grava os atos novos.
     *
     * @param  array<int,string>  $categorias
     * @param  int  $pararVistos  Se >0 (modo FORWARD): para uma categoria após N
     *                            atos JÁ VISTOS consecutivos. A listagem é
     *                            recente-primeiro, então quando bate no que já
     *                            temos, o resto da janela também já está — não
     *                            re-pagina o histórico inteiro a cada ciclo.
     * @param  callable|null  $onCategoria  fn(string $cat, int $novos): void
     * @return array{vistos:int,novos:int}
     */
    public function ingerirJanela(
        array $categorias,
        Carbon $ini,
        Carbon $fim,
        int $maxPaginas,
        int $pararVistos = 0,
        ?callable $onCategoria = null
    ): array {
        $vistos = 0;
        $novos = 0;

        foreach ($categorias as $cat) {
            $catNovos = 0;
            $seguidosVistos = 0;
            foreach ($this->conector->atos($cat, $ini, $fim, $maxPaginas) as $ato) {
                $vistos++;
                $hash = sha1((string) $ato['ato_id']);
                if (DB::table('jr_dom_atos')->where('hash', $hash)->exists()) {
                    $seguidosVistos++;
                    // FORWARD: estourou o teto de vistos consecutivos -> resto já é histórico.
                    if ($pararVistos > 0 && $seguidosVistos >= $pararVistos) {
                        break;
                    }
                    continue;
                }
                $seguidosVistos = 0;
                DB::table('jr_dom_atos')->insert([
                    'ato_id' => $ato['ato_id'],
                    'hash' => $hash,
                    'titulo' => $ato['titulo'],
                    'municipio' => $ato['municipio'],
                    'orgao' => $ato['orgao'],
                    'categoria' => $ato['categoria'],
                    'modalidade' => $ato['modalidade'],
                    'objeto' => $ato['objeto'],
                    'objeto_limpo' => $ato['objeto_limpo'],
                    'valor' => $ato['valor'],
                    'fornecedor' => $ato['fornecedor'],
                    'data_pub' => $ato['data_pub'],
                    'url_fonte' => $ato['url_fonte'],
                    'url_pdf' => $ato['url_pdf'],
                    'texto_bruto' => $ato['texto_bruto'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $novos++;
                $catNovos++;
            }
            if ($onCategoria) {
                $onCategoria($cat, $catNovos);
            }
        }

        return ['vistos' => $vistos, 'novos' => $novos];
    }
}
