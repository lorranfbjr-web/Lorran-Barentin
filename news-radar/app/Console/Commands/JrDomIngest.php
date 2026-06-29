<?php

namespace App\Console\Commands;

use App\Services\Jr\DomConector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FASE 1 — Ingestão DOM/SC (PoC). Varre a busca pública do Diário Oficial dos
 * Municípios de SC por categorias de compras (licitação/contrato/dispensa/ata
 * RP), TODOS os municípios, janela de N dias, e grava em jr_dom_atos.
 *
 * MANUAL nesta PoC (NÃO agendado). Idempotente: dedup por hash(ato_id) — re-rodar
 * não duplica. ADITIVO e ISOLADO: não toca em nada do radar/juiz/captura.
 */
class JrDomIngest extends Command
{
    protected $signature = 'jr:dom-ingest '
        . '{--dias= : Janela em dias (default config dom.dias=14)} '
        . '{--categorias= : Lista separada por vírgula (default config dom.categorias)} '
        . '{--max-paginas= : Teto de páginas por categoria, 10 atos/pág (default config)} '
        . '{--municipio= : Filtra por entidade/município (texto livre; default todos)} '
        . '{--dry : Só mostra os totais reportados pela busca, sem gravar}';

    protected $description = 'Ingestão DOM/SC: minera atos de compras públicas de todos os municípios de SC (janela N dias) pra jr_dom_atos.';

    public function handle(DomConector $conector): int
    {
        $cfg = config('dom');
        $dias = (int) ($this->option('dias') ?: $cfg['dias']);
        $maxPag = (int) ($this->option('max-paginas') ?: $cfg['max_paginas']);
        $categorias = $this->option('categorias')
            ? array_map('trim', explode(',', (string) $this->option('categorias')))
            : $cfg['categorias'];

        $fim = Carbon::now();
        $ini = $fim->copy()->subDays($dias);
        $this->info(sprintf('Janela: %s → %s (%d dias) | categorias: %s',
            $ini->toDateString(), $fim->toDateString(), $dias, implode(', ', $categorias)));

        if ($this->option('dry')) {
            foreach ($categorias as $cat) {
                $this->line(sprintf('  %-28s total reportado: %d', $cat, $conector->total($cat, $ini, $fim)));
            }

            return self::SUCCESS;
        }

        $novos = 0;
        $vistos = 0;
        $bar = null;
        foreach ($categorias as $cat) {
            $this->line("→ {$cat}");
            $catNovos = 0;
            foreach ($conector->atos($cat, $ini, $fim, $maxPag) as $ato) {
                $vistos++;
                $hash = sha1((string) $ato['ato_id']);
                // dedup idempotente: se já existe, pula (categoria já gravada na 1ª aparição)
                $existe = DB::table('jr_dom_atos')->where('hash', $hash)->exists();
                if ($existe) {
                    continue;
                }
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
            $this->line("   +{$catNovos} novos");
        }

        $municipios = DB::table('jr_dom_atos')->distinct()->count('municipio');
        $total = DB::table('jr_dom_atos')->count();
        $this->newLine();
        $this->info(sprintf('Ingestão: %d atos vistos, %d novos gravados.', $vistos, $novos));
        $this->info(sprintf('Tabela jr_dom_atos: %d atos no total, %d municípios distintos.', $total, $municipios));

        return self::SUCCESS;
    }
}
