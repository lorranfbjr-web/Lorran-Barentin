<?php

namespace App\Console\Commands;

use App\Support\TituloFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingere o sinal de TÍTULOS (GA4) com features por regra, analisa views x
 * engajamento, e produz: (1) Manual de títulos JR (texto + HTML) e
 * (2) storage/app/jr-titulo-regras.json (pesos pro cérebro do JR Pauta).
 *
 * Read-only no GA/pipeline; não publica em produção; só LÊ o JSON do extrator
 * e grava na tabela NOVA + arquivos NOVOS.
 */
class JrTituloIngest extends Command
{
    protected $signature = 'jrtitulo:ingest {--file=} {--print-only}';

    protected $description = 'Ingere títulos (GA4) em jr_titulo_sinal, gera o Manual de títulos JR e o jr-titulo-regras.json.';

    private string $T = 'jr_titulo_sinal';

    public function handle(): int
    {
        if (! $this->option('print-only')) {
            $file = $this->option('file') ?: storage_path('app/jr-titulo-sinal.json');
            if (! is_file($file)) {
                $this->error("Arquivo não encontrado: {$file}. Rode antes jr_titulo_extract.py.");
                return self::FAILURE;
            }
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data) || empty($data['periodos'])) {
                $this->error('JSON inválido/vazio.');
                return self::FAILURE;
            }
            $now = Carbon::now();
            $total = 0;
            foreach ($data['periodos'] as $periodo => $bloco) {
                $chunk = [];
                foreach (($bloco['rows'] ?? []) as $r) {
                    $f = TituloFeatures::extract((string) $r['page_title'], (string) $r['page_path']);
                    $chunk[] = array_merge([
                        'page_path' => (string) $r['page_path'],
                        'page_title' => (string) $r['page_title'],
                        'periodo' => (string) $periodo,
                        'views' => (int) ($r['views'] ?? 0),
                        'engagement_seconds' => (float) ($r['engagement_seconds'] ?? 0),
                        'engagement_rate' => (float) ($r['engagement_rate'] ?? 0),
                        'engaj_por_view' => (float) ($r['engaj_por_view'] ?? 0),
                        'created_at' => $now,
                    ], $f);
                }
                foreach (array_chunk($chunk, 300) as $part) {
                    DB::table($this->T)->upsert($part, ['page_path', 'periodo'], [
                        'page_title','views','engagement_seconds','engagement_rate','engaj_por_view',
                        'tem_aspas','aspas_inicio','tem_numero','tem_cidade','cidade','cidade_posicao',
                        'cliffhanger','tema','gancho','comprimento_chars','comprimento_palavras','registro','created_at',
                    ]);
                }
                $total += count($chunk);
                $this->info("periodo {$periodo}: " . count($chunk) . ' títulos');
            }
            $this->info("Total ingerido/atualizado: {$total}");
        }

        $a = $this->analise();
        $manual = $this->manual($a);

        // SAÍDA 1 — manual em texto
        file_put_contents(storage_path('app/jr-titulo-manual.txt'), $manual);
        // SAÍDA 2 — regras JSON
        $regras = $this->regras($a);
        file_put_contents(storage_path('app/jr-titulo-regras.json'),
            json_encode($regras, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        // HTML
        $htmlPath = public_path('_tmp_jrtitulo/manual.html');
        if (! is_dir(dirname($htmlPath))) mkdir(dirname($htmlPath), 0775, true);
        file_put_contents($htmlPath, $this->html($manual));

        $this->line($manual);
        $this->newLine();
        $this->info('Arquivos: storage/app/jr-titulo-manual.txt | storage/app/jr-titulo-regras.json | ' . $htmlPath);
        return self::SUCCESS;
    }

    // ===================================================================== análise
    private function analise(): array
    {
        $all = DB::table($this->T)->get();
        $a = [];
        $a['n'] = $all->count();
        $a['baseViews'] = $all->avg('views') ?: 0;
        $a['baseEpv'] = $all->avg('engaj_por_view') ?: 0;

        $grp = fn ($col) => $this->grupo($all, fn ($r) => $r->$col);
        $a['porTema']     = $grp('tema');
        $a['porGancho']   = $grp('gancho');
        $a['porRegistro'] = $grp('registro');
        $a['porPosCidade']= $this->grupo($all->where('tem_cidade', 1), fn ($r) => $r->cidade_posicao ?? '(n/d)');

        // booleanas: TRUE vs FALSE
        foreach (['tem_aspas','aspas_inicio','tem_numero','tem_cidade','cliffhanger'] as $b) {
            $a['bool'][$b] = [
                'sim' => $this->stats($all->where($b, 1)),
                'nao' => $this->stats($all->where($b, 0)),
            ];
        }

        // comprimento (faixas em chars)
        $faixa = function ($c) {
            return $c <= 40 ? '01 ≤40' : ($c <= 55 ? '02 41-55' : ($c <= 70 ? '03 56-70'
                : ($c <= 85 ? '04 71-85' : '05 >85')));
        };
        $a['porComprimento'] = $this->grupo($all, fn ($r) => $faixa($r->comprimento_chars));

        // cidades (top por nº)
        $a['porCidade'] = $this->grupo($all->where('tem_cidade', 1), fn ($r) => $r->cidade ?: '(n/d)');

        // combos
        $a['ganchoTema'] = $this->grupo($all, fn ($r) => $r->gancho . ' × ' . $r->tema, 20);
        $a['ganchoCidade'] = $this->grupo($all->where('tem_cidade', 1), fn ($r) => $r->gancho . ' × ' . $r->cidade, 10);
        $a['leveAspas'] = [
            'leve+aspasInicio' => $this->stats($all->where('registro','leve')->where('aspas_inicio',1)),
            'leve+semAspas'    => $this->stats($all->where('registro','leve')->where('aspas_inicio',0)),
            'pesado+aspasInicio'=> $this->stats($all->where('registro','pesado')->where('aspas_inicio',1)),
            'pesado+semAspas'  => $this->stats($all->where('registro','pesado')->where('aspas_inicio',0)),
        ];

        // tendência 2025 -> 2026 (share de views por tema)
        $a['tendencia'] = $this->tendencia();

        // anti-padrões (quartil pior por views, período 2026)
        $a['anti'] = $this->antipadroes();

        return $a;
    }

    private function grupo($coll, callable $key, int $minN = 1): array
    {
        $g = [];
        foreach ($coll as $r) {
            $k = $key($r);
            $g[$k] ??= ['n' => 0, 'v' => 0, 'e' => 0];
            $g[$k]['n']++;
            $g[$k]['v'] += (int) $r->views;
            $g[$k]['e'] += (float) $r->engaj_por_view;
        }
        $out = [];
        foreach ($g as $k => $x) {
            if ($x['n'] < $minN) continue;
            $out[$k] = [
                'n' => $x['n'],
                'avgViews' => $x['v'] / $x['n'],
                'avgEpv' => $x['e'] / $x['n'],
            ];
        }
        uasort($out, fn ($p, $q) => $q['avgViews'] <=> $p['avgViews']);
        return $out;
    }

    private function stats($coll): array
    {
        $n = $coll->count();
        return [
            'n' => $n,
            'avgViews' => $n ? $coll->avg('views') : 0,
            'avgEpv' => $n ? $coll->avg('engaj_por_view') : 0,
        ];
    }

    private function tendencia(): array
    {
        $out = [];
        foreach (['2025','2026'] as $p) {
            $rows = DB::table($this->T)->where('periodo', $p)->get();
            $totV = max((int) $rows->sum('views'), 1);
            $perTema = [];
            foreach ($rows as $r) {
                $perTema[$r->tema] = ($perTema[$r->tema] ?? 0) + (int) $r->views;
            }
            foreach ($perTema as $tema => $v) {
                $out[$tema][$p] = $v / $totV;
            }
        }
        $res = [];
        foreach ($out as $tema => $sh) {
            $s25 = $sh['2025'] ?? 0;
            $s26 = $sh['2026'] ?? 0;
            $res[$tema] = ['s2025' => $s25, 's2026' => $s26, 'delta' => $s26 - $s25];
        }
        uasort($res, fn ($p, $q) => $q['delta'] <=> $p['delta']);
        return $res;
    }

    private function antipadroes(): array
    {
        $rows = DB::table($this->T)->where('periodo', '2026')->orderBy('views')->get();
        $n = $rows->count();
        if ($n < 8) return [];
        $q = (int) floor($n / 4);
        $pior = $rows->take($q);
        $melhor = $rows->slice($n - $q, $q);
        $perfil = function ($coll) {
            $c = $coll->count() ?: 1;
            return [
                'n' => $coll->count(),
                'avgViews' => $coll->avg('views'),
                'avgEpv' => $coll->avg('engaj_por_view'),
                'pctSemCidade' => 100 * $coll->where('tem_cidade', 0)->count() / $c,
                'pctGanchoOutro' => 100 * $coll->whereIn('gancho', ['outro'])->count() / $c,
                'pctPesado' => 100 * $coll->where('registro', 'pesado')->count() / $c,
                'pctSemNumero' => 100 * $coll->where('tem_numero', 0)->count() / $c,
                'pctSemAspas' => 100 * $coll->where('tem_aspas', 0)->count() / $c,
                'avgChars' => $coll->avg('comprimento_chars'),
            ];
        };
        return ['pior' => $perfil($pior), 'melhor' => $perfil($melhor)];
    }

    // ===================================================================== manual (texto)
    private function manual(array $a): string
    {
        $L = [];
        $nf = fn ($v) => number_format((int) round($v), 0, ',', '.');
        $f1 = fn ($v) => number_format((float) $v, 1, ',', '.');
        $liftV = fn ($v) => ($a['baseViews'] ? $v / $a['baseViews'] : 0);
        $liftE = fn ($v) => ($a['baseEpv'] ? $v / $a['baseEpv'] : 0);

        $L[] = '================================================================';
        $L[] = '   MANUAL DE TÍTULOS — JORNAL RAZÃO  (baseado em GA4, dado real)';
        $L[] = '   Base: ' . $a['n'] . ' títulos (2025 + 2026). VIEWS=clique · ENGAJ/VIEW=atenção(s)';
        $L[] = '   Médias gerais: ' . $nf($a['baseViews']) . ' views · ' . $f1($a['baseEpv']) . 's por view';
        $L[] = '================================================================';

        // tabela helper
        $tab = function (string $titulo, array $g, int $lim = 12) use (&$L, $nf, $f1, $liftV, $liftE) {
            $L[] = '';
            $L[] = '── ' . $titulo;
            $L[] = sprintf('   %-26s %5s %10s %8s %8s', 'categoria', 'n', 'views', 'eng/view', 'lift-v');
            $i = 0;
            foreach ($g as $k => $x) {
                if ($i++ >= $lim) break;
                $L[] = sprintf('   %-26s %5d %10s %7ss  %5.2fx',
                    mb_strimwidth($k, 0, 26, '', 'UTF-8'), $x['n'], $nf($x['avgViews']),
                    $f1($x['avgEpv']), $liftV($x['avgViews']));
            }
        };

        // FÓRMULAS VENCEDORAS = combos gancho×tema com n>=15, top por views
        $L[] = '';
        $L[] = '########## FÓRMULAS VENCEDORAS (ranqueadas por views/matéria) ##########';
        $formulas = array_filter($a['ganchoTema'],
            fn ($x, $k) => $x['n'] >= 15 && ! str_starts_with($k, 'outro ×'),
            ARRAY_FILTER_USE_BOTH);
        $i = 0;
        foreach ($formulas as $k => $x) {
            if ($i++ >= 10) break;
            $L[] = sprintf('  %2d. %-34s  %s views · %ss atenção · %.2fx · n=%d',
                $i, $k, $nf($x['avgViews']), $f1($x['avgEpv']), $liftV($x['avgViews']), $x['n']);
        }

        $tab('TEMA', $a['porTema']);
        $tab('GANCHO', $a['porGancho']);
        $tab('REGISTRO (leve x pesado)', $a['porRegistro'], 5);
        $tab('COMPRIMENTO (chars)', $this->sortKey($a['porComprimento']), 6);
        $tab('CIDADE (top por nº)', $a['porCidade'], 12);
        $tab('POSIÇÃO DA CIDADE no título', $a['porPosCidade'], 5);

        // booleanas
        $L[] = '';
        $L[] = '── FEATURES (com x sem) — lift de views e de atenção';
        $L[] = sprintf('   %-16s %12s %12s %10s', 'feature', 'COM(v/epv)', 'SEM(v/epv)', 'lift-v');
        $labels = ['tem_aspas'=>'aspas (qualquer)','aspas_inicio'=>'aspas no início','tem_numero'=>'número','tem_cidade'=>'cidade','cliffhanger'=>'cliffhanger'];
        foreach ($a['bool'] as $b => $x) {
            $lift = $x['nao']['avgViews'] ? $x['sim']['avgViews'] / $x['nao']['avgViews'] : 0;
            $L[] = sprintf('   %-16s %6s/%4ss %6s/%4ss   %5.2fx',
                $labels[$b] ?? $b, $nf($x['sim']['avgViews']), $f1($x['sim']['avgEpv']),
                $nf($x['nao']['avgViews']), $f1($x['nao']['avgEpv']), $lift);
        }

        // combos
        $tab('COMBO gancho × cidade (n≥8)', array_filter($a['ganchoCidade'], fn ($x) => $x['n'] >= 8), 10);
        $L[] = '';
        $L[] = '── COMBO registro leve × aspas no início';
        foreach ($a['leveAspas'] as $k => $x) {
            $L[] = sprintf('   %-20s n=%-4d %s views · %ss', $k, $x['n'], $nf($x['avgViews']), $f1($x['avgEpv']));
        }

        // tendência
        $L[] = '';
        $L[] = '── TENDÊNCIA 2025 → 2026 (share de views por tema; + subiu / - caiu)';
        $L[] = sprintf('   %-20s %8s %8s %8s', 'tema', '2025', '2026', 'Δ p.p.');
        foreach ($a['tendencia'] as $tema => $t) {
            $L[] = sprintf('   %-20s %7.1f%% %7.1f%% %+7.1f', $tema, $t['s2025']*100, $t['s2026']*100, ($t['delta'])*100);
        }

        // anti-padrões
        if (! empty($a['anti'])) {
            $p = $a['anti']['pior']; $m = $a['anti']['melhor'];
            $L[] = '';
            $L[] = '── ANTI-PADRÕES (quartil PIOR vs MELHOR por views, 2026)';
            $L[] = sprintf('   %-22s %12s %12s', 'métrica', 'PIOR 25%', 'MELHOR 25%');
            $L[] = sprintf('   %-22s %11s %12s', 'views médio', $nf($p['avgViews']), $nf($m['avgViews']));
            $L[] = sprintf('   %-22s %10s%% %11s%%', '% sem cidade', $f1($p['pctSemCidade']), $f1($m['pctSemCidade']));
            $L[] = sprintf('   %-22s %10s%% %11s%%', '% gancho "outro"', $f1($p['pctGanchoOutro']), $f1($m['pctGanchoOutro']));
            $L[] = sprintf('   %-22s %10s%% %11s%%', '% sem número', $f1($p['pctSemNumero']), $f1($m['pctSemNumero']));
            $L[] = sprintf('   %-22s %10s%% %11s%%', '% sem aspas', $f1($p['pctSemAspas']), $f1($m['pctSemAspas']));
            $L[] = sprintf('   %-22s %10s%% %11s%%', '% registro pesado', $f1($p['pctPesado']), $f1($m['pctPesado']));
            $L[] = sprintf('   %-22s %11s %12s', 'comprimento médio', $f1($p['avgChars']), $f1($m['avgChars']));
        }

        // REGRAS CONCRETAS
        $L[] = '';
        $L[] = '########## REGRAS CONCRETAS (o que fazer) ##########';
        foreach ($this->regrasTexto($a) as $r) { $L[] = '  • ' . $r; }

        $L[] = '';
        $L[] = '########## O QUE EVITAR ##########';
        foreach ($this->evitarTexto($a) as $r) { $L[] = '  ✗ ' . $r; }

        // EXEMPLOS REAIS
        $L[] = '';
        $L[] = '########## EXEMPLOS REAIS DO ACERVO (por fórmula) ##########';
        foreach ($this->exemplos() as $ex) {
            $L[] = sprintf('  [%s]', $ex['rotulo']);
            foreach ($ex['itens'] as $it) {
                $L[] = sprintf('     %s views · %ss  %s', $nf($it->views), $f1($it->engaj_por_view),
                    TituloFeatures::stripSuffix($it->page_title));
            }
        }

        $L[] = '';
        $L[] = '================================================================';
        return implode("\n", $L);
    }

    private function sortKey(array $g): array
    {
        ksort($g);
        return $g;
    }

    private function regrasTexto(array $a): array
    {
        $r = [];
        $b = $a['bool'];
        $f1 = fn ($v) => number_format((float) $v, 1, ',', '.');
        $aspasEng = $b['tem_aspas']['sim']['avgEpv'];
        $semAspasEng = $b['tem_aspas']['nao']['avgEpv'];
        $liftAspasEng = $semAspasEng ? $aspasEng / $semAspasEng : 0;

        // ganchos campeões de CLIQUE (views), excluindo "outro"
        $gv = $a['porGancho'];
        uasort($gv, fn ($p, $q) => $q['avgViews'] <=> $p['avgViews']);
        $topClique = [];
        foreach ($gv as $k => $x) { if ($k !== 'outro') { $topClique[] = $k; } if (count($topClique) >= 3) break; }

        $r[] = sprintf('ASPAS = ATENÇÃO, não clique. No acervo, aspas é table-stakes (clique igual), mas SEGURA muito mais atenção: %ss com aspas vs %ss sem (%.2fx). Use fala real entre aspas para reter o leitor — não esperando mais clique, e sim leitura mais longa.',
            $f1($aspasEng), $f1($semAspasEng), $liftAspasEng);
        $r[] = sprintf('GANCHOS QUE MAIS DÃO CLIQUE: %s. Conquista/superação e indignação são os campeões de views — prefira-os quando o fato permitir.',
            implode(', ', $topClique));
        $r[] = sprintf('CIDADE: ancore sempre na cidade da região. Não é a cidade que multiplica o clique (é padrão da casa), mas a AUSÊNCIA dela pesa: %.0f%% do quartil PIOR não tem cidade. Posição que melhor rende: %s.',
            $a['anti']['pior']['pctSemCidade'] ?? 45, $this->melhorPosCidade($a));
        $r[] = 'NÚMERO: use número concreto (R$, "X anos", "X mil", %) quando o fato tiver — especificidade e credibilidade (ex.: "R$ 400 milhões e 700 empregos").';
        $r[] = sprintf('COMPRIMENTO: a faixa de maior rendimento é %s — o público do JR responde bem a título informativo/longo. Evite título curto e genérico (≤55 chars rendem menos).',
            $this->melhorFaixa($a));
        $r[] = sprintf('TEMA LEVE VENDE: registro leve (economia/gente/bicho/meio ambiente/turismo) rende %.2fx mais views/matéria. Importante: em pauta leve, NÃO force aspas no início — leve sem aspas rendeu %s views vs %s com aspas.',
            ($a['baseViews'] ? $a['porRegistro']['leve']['avgViews'] / $a['baseViews'] : 0),
            number_format((int) ($a['leveAspas']['leve+semAspas']['avgViews'] ?? 0), 0, ',', '.'),
            number_format((int) ($a['leveAspas']['leve+aspasInicio']['avgViews'] ?? 0), 0, ',', '.'));
        return $r;
    }

    private function evitarTexto(array $a): array
    {
        $e = [];
        if (! empty($a['anti'])) {
            $p = $a['anti']['pior'];
            $e[] = sprintf('Título SEM cidade (quartil pior tem %.0f%% sem cidade).', $p['pctSemCidade']);
            $e[] = sprintf('Título genérico sem gancho (%.0f%% do pior são gancho "outro").', $p['pctGanchoOutro']);
            $e[] = sprintf('Comprimento médio do pior quartil: %.0f chars — fuja do tamanho que não engaja.', $p['avgChars']);
        }
        $e[] = 'Aspas sem fala literal real (só use aspas quando houver citação verdadeira atribuída).';
        $e[] = 'Jargão/juridiquês e sensacionalismo vazio sem fato no corpo.';
        $e[] = 'Afirmar autoria/causa/morte sem confirmação — use "segundo a polícia", "a polícia apura".';
        return $e;
    }

    private function melhorPosCidade(array $a): string
    {
        $g = $a['porPosCidade'];
        unset($g['(n/d)']);
        $k = array_key_first($g);
        return $k ?: 'fim';
    }

    private function melhorFaixa(array $a): string
    {
        $g = $a['porComprimento'];
        uasort($g, fn ($p, $q) => $q['avgViews'] <=> $p['avgViews']);
        $k = array_key_first($g);
        return $k ? trim(preg_replace('/^\d+\s/', '', $k)) . ' chars' : '56-70 chars';
    }

    private function melhorGanchoAtencao(array $a): string
    {
        $g = $a['porGancho'];
        uasort($g, fn ($p, $q) => $q['avgEpv'] <=> $p['avgEpv']);
        $k = array_key_first($g);
        return (string) $k;
    }

    private function exemplos(): array
    {
        $pick = fn ($q, $lim = 3) => $q->orderByDesc('views')->limit($lim)->get();
        return [
            ['rotulo' => 'Fala entre aspas no início + cidade',
             'itens' => $pick(DB::table($this->T)->where('aspas_inicio', 1)->where('tem_cidade', 1))],
            ['rotulo' => 'Tema leve (gente/bicho) com cidade',
             'itens' => $pick(DB::table($this->T)->where('registro', 'leve')->where('tem_cidade', 1))],
            ['rotulo' => 'Economia/negócios com número',
             'itens' => $pick(DB::table($this->T)->where('tema', 'economia_negocios')->where('tem_numero', 1))],
            ['rotulo' => 'Surpresa/curiosidade (cliffhanger)',
             'itens' => $pick(DB::table($this->T)->where('cliffhanger', 1))],
            ['rotulo' => 'Indignação ("farra", "absurdo"...)',
             'itens' => $pick(DB::table($this->T)->where('gancho', 'indignacao'))],
        ];
    }

    // ===================================================================== regras (JSON)
    private function regras(array $a): array
    {
        $liftV = fn ($v) => round($a['baseViews'] ? $v / $a['baseViews'] : 0, 3);
        $liftE = fn ($v) => round($a['baseEpv'] ? $v / $a['baseEpv'] : 0, 3);

        $pesosFeature = [];
        foreach ($a['bool'] as $b => $x) {
            $pesosFeature[$b] = [
                'lift_views' => round($x['nao']['avgViews'] ? $x['sim']['avgViews'] / $x['nao']['avgViews'] : 0, 3),
                'lift_engajamento' => round($x['nao']['avgEpv'] ? $x['sim']['avgEpv'] / $x['nao']['avgEpv'] : 0, 3),
                'n_sim' => $x['sim']['n'], 'n_nao' => $x['nao']['n'],
            ];
        }
        $temaMult = [];
        foreach ($a['porTema'] as $tema => $x) {
            $temaMult[$tema] = ['lift_views' => $liftV($x['avgViews']), 'lift_engajamento' => $liftE($x['avgEpv']), 'n' => $x['n']];
        }
        $ganchoMult = [];
        foreach ($a['porGancho'] as $g => $x) {
            $ganchoMult[$g] = ['lift_views' => $liftV($x['avgViews']), 'lift_engajamento' => $liftE($x['avgEpv']), 'n' => $x['n']];
        }

        // Pontos aditivos pro score vai_feed. Usa lift MISTO (clique + atenção),
        // porque feature boa nem sempre puxa clique, mas segura leitura.
        $blend = fn ($lv, $le) => 0.6 * $lv + 0.4 * $le;
        $pts = fn ($lift, $max = 40) => max(0, min($max, (int) round(($lift - 1) * $max)));
        $featPts = function ($key) use ($pesosFeature, $blend, $pts) {
            $f = $pesosFeature[$key] ?? ['lift_views' => 1, 'lift_engajamento' => 1];
            return $pts($blend($f['lift_views'], $f['lift_engajamento']), 15);
        };
        $ganchoPts = [];
        foreach ($ganchoMult as $g => $x) { $ganchoPts[$g] = $pts($blend($x['lift_views'], $x['lift_engajamento']), 40); }
        $temaPts = [];
        foreach ($temaMult as $tt => $x) { $temaPts[$tt] = $pts($blend($x['lift_views'], $x['lift_engajamento']), 30); }

        $pesosScore = [
            'formula' => 'vai_feed = base(20) + tema_pts[tema] + gancho_pts[gancho] + feature_pts(soma) ; teto 100',
            'base' => 20,
            'feature_pts' => [
                'aspas_inicio' => $featPts('aspas_inicio'),
                'tem_cidade'   => $featPts('tem_cidade'),
                'tem_numero'   => $featPts('tem_numero'),
                'cliffhanger'  => $featPts('cliffhanger'),
            ],
            'tema_pts' => $temaPts,
            'gancho_pts' => $ganchoPts,
        ];

        return [
            'gerado_em' => Carbon::now()->toIso8601String(),
            'fonte' => 'GA4 jr_titulo_sinal (2025+2026)',
            'baseline' => ['avg_views' => round($a['baseViews'], 1), 'avg_engaj_por_view' => round($a['baseEpv'], 2), 'n' => $a['n']],
            'observacao' => 'lift_views = clique (alcance); lift_engajamento = atenção/retenção. Use os dois: alcance pra feed, atenção pra qualidade.',
            'pesos_feature' => $pesosFeature,
            'pesos_score_vai_feed' => $pesosScore,
            'multiplicador_tema' => $temaMult,
            'multiplicador_gancho' => $ganchoMult,
            'comprimento_ideal_chars' => $this->melhorFaixa($a),
            'cidade_posicao_preferida' => $this->melhorPosCidade($a),
            'diretrizes_geracao' => [
                'Abra com a fala mais forte entre aspas quando houver citação real atribuída.',
                'Inclua sempre a cidade da região (preferência de posição em "cidade_posicao_preferida").',
                'Use número concreto (R$, idade, quantidade, %) quando o fato fornecer.',
                'Tema leve (economia/gente/bicho/meio ambiente/turismo) tem alto alcance por matéria — priorize no feed.',
                'Evite título genérico sem gancho e sem cidade (perfil do quartil de pior desempenho).',
                'Não use aspas sem fala literal real; não afirme autoria/causa sem confirmação.',
            ],
            'anti_padroes' => $a['anti'] ?? null,
            'tendencia_share_views' => $a['tendencia'],
        ];
    }

    // ===================================================================== HTML
    private function html(string $manual): string
    {
        $e = htmlspecialchars($manual, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow, noarchive">'
            . '<title>Manual de Títulos — Jornal Razão</title>'
            . '<style>body{margin:0;background:#0f1419;color:#e6e6e6;font:13px/1.5 ui-monospace,Menlo,Consolas,monospace}'
            . '.wrap{max-width:980px;margin:0 auto;padding:14px}'
            . 'pre{white-space:pre-wrap;word-wrap:break-word;margin:0}'
            . 'h1{font:600 18px system-ui;margin:6px 0 12px}</style></head><body><div class="wrap">'
            . '<h1>📝 Manual de Títulos — Jornal Razão</h1><pre>' . $e . '</pre></div></body></html>';
    }
}
