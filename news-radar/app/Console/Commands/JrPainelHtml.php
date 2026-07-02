<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gera UM painel HTML único (perfil de demanda GA4 + rascunhos editoriais)
 * para leitura no celular. Escreve em local INTERNO (storage/app) por padrão.
 *
 * NÃO publica em diretório público — isso é decisão do dono (rodar o cp por
 * conta própria). Só LÊ as tabelas jr_sinal_interesse e jr_pauta_rascunhos.
 */
class JrPainelHtml extends Command
{
    protected $signature = 'jrpainel:html {--out= : Caminho de saída (default: storage/app/jr-painel.html)}';

    protected $description = 'Monta um painel HTML (demanda GA4 + rascunhos) para abrir no celular. Só leitura; não publica.';

    public function handle(): int
    {
        $out = $this->option('out') ?: storage_path('app/jr-painel.html');
        $sinal = app(JrSinalInteresse::class);

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $nf = fn ($v) => number_format((int) $v, 0, ',', '.');

        // ---- Perfil de demanda ----
        $a = $sinal->analise();
        $rows = $a['rows'];

        $cob = '';
        foreach (['2025', '2026', 'total'] as $p) {
            $c = $a['cobertura'][$p] ?? ['qtd' => 0, 'views' => 0];
            $cob .= '<span class="rb">' . $e($p) . ': <b>' . $c['qtd'] . '</b> matérias · ' . $nf($c['views']) . ' views</span>';
        }

        $tbl = function (string $titulo, array $count, array $views, int $limit) use ($e, $nf) {
            arsort($views);
            $h = '<h3>' . $e($titulo) . '</h3><table><thead><tr><th>views</th><th>qtd</th><th>categoria</th></tr></thead><tbody>';
            $i = 0;
            foreach ($views as $k => $v) {
                if ($i++ >= $limit) break;
                $h .= '<tr><td class="n">' . $nf($v) . '</td><td class="n">' . ($count[$k] ?? 0) . '</td><td>' . $e($k) . '</td></tr>';
            }
            return $h . '</tbody></table>';
        };

        $temaEd = $a['temaEd'];
        arsort($temaEd);
        $edHtml = '<h3>Temas por editoria (path)</h3><table><thead><tr><th>qtd</th><th>editoria</th></tr></thead><tbody>';
        $i = 0;
        foreach ($temaEd as $k => $c) {
            if ($i++ >= 12) break;
            $edHtml .= '<tr><td class="n">' . $c . '</td><td>' . $e($k) . '</td></tr>';
        }
        $edHtml .= '</tbody></table>';

        $temasKw  = $tbl('Temas por palavra-chave (não exclusivo)', $a['temaKwCount'], $a['temaKwViews'], 12);
        $cidades  = $tbl('Cidades (token no título/path)', $a['cidadeCount'], $a['cidadeViews'], 15);
        $ganchos  = $tbl('Ganchos / ângulos', $a['ganchoCount'], $a['ganchoViews'], 8);

        $leves = $a['leves'];
        usort($leves, fn ($x, $y) => $y->views <=> $x->views);
        $levesHtml = '<ol class="lista">';
        foreach (array_slice($leves, 0, 25) as $r) {
            $levesHtml .= '<li><span class="v">' . $nf($r->views) . '</span> ' . $e($sinal->shorten($r->page_title)) . '</li>';
        }
        $levesHtml .= '</ol>';

        $topHtml = '<ol class="lista">';
        foreach ($rows->take(20) as $r) {
            $topHtml .= '<li><span class="v">' . $nf($r->views) . '</span> <span class="ed">' . $e($sinal->editoria($r->page_path)) . '</span> ' . $e($sinal->shorten($r->page_title)) . '</li>';
        }
        $topHtml .= '</ol>';

        // ---- Rascunhos ----
        $rasc = DB::table('jr_pauta_rascunhos')->orderBy('id')->get();
        $cards = '';
        foreach ($rasc as $idx => $r) {
            $titulos = json_decode($r->titulos, true) ?: [];
            $tags    = json_decode($r->tags, true) ?: [];
            $lacunas = json_decode($r->lacunas, true) ?: [];

            $tit = '';
            foreach ($titulos as $t) { $tit .= '<li>' . $e($t) . '</li>'; }
            $blocos = '';
            foreach (explode("\n", $r->materia) as $ln) {
                $ln = trim($ln);
                if ($ln === '') continue;
                $blocos .= preg_match('/^\(\d\)\s/', $ln)
                    ? '<h4>' . $e($ln) . '</h4>'
                    : '<p>' . $e($ln) . '</p>';
            }
            $tg = '';
            foreach ($tags as $t) { $tg .= '<span class="tag">' . $e($t) . '</span>'; }
            $lc = '';
            foreach ($lacunas as $l) { $lc .= '<li>' . $e($l) . '</li>'; }

            $cards .= '
            <article class="card ' . $e($r->temperatura) . '">
              <header>
                <div class="num">#' . sprintf('%02d', $idx + 1) . '</div>
                <div class="meta"><h2>' . $e($titulos[0] ?? '(sem título)') . '</h2>
                  <div class="src">' . $e($r->fonte) . ' · ' . $e($r->cidade ?? 'cidade não informada') . '</div></div>
                <div class="badges"><span class="badge t-' . $e($r->temperatura) . '">' . $e(strtoupper($r->temperatura)) . '</span>
                  <span class="badge c-' . $e($r->confianca) . '">confiança ' . $e($r->confianca) . '</span></div>
              </header>
              <p class="lf"><strong>Linha-fina:</strong> ' . $e($r->linha_fina) . '</p>
              <details open><summary>Títulos (' . count($titulos) . ')</summary><ol>' . $tit . '</ol></details>
              <details><summary>Matéria</summary><div class="materia">' . $blocos . '</div></details>
              <div class="tags">' . $tg . '</div>
              <details><summary>Lacunas (' . count($lacunas) . ')</summary><ul class="lacunas">' . $lc . '</ul></details>
            </article>';
        }

        $html = $this->shell($cob, $edHtml, $temasKw, $cidades, $ganchos, $levesHtml, $topHtml, $cards, $rows->count(), $rasc->count());

        $dir = dirname($out);
        if (! is_dir($dir)) mkdir($dir, 0775, true);
        file_put_contents($out, $html);

        $this->info('Painel gerado em: ' . $out);
        $this->line('Para abrir local: xdg-open "' . $out . '"');
        return self::SUCCESS;
    }

    private function shell(string $cob, string $ed, string $kw, string $cid, string $gan, string $leves, string $top, string $cards, int $nSinal, int $nRasc): string
    {
        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel JR — Demanda + Rascunhos</title>
<style>
 :root{--q:#d64545;--f:#2f6fb0;--bg:#f4f5f7;--card:#fff;--line:#e3e6ea;--ink:#1f2933;--mut:#6b7280}
 *{box-sizing:border-box}
 body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 -apple-system,Segoe UI,Roboto,Arial,sans-serif}
 .wrap{max-width:880px;margin:0 auto;padding:0 14px 64px}
 .top{position:sticky;top:0;background:var(--bg);padding:12px 0;border-bottom:1px solid var(--line);z-index:9}
 .top h1{margin:0 0 8px;font-size:19px}
 nav a{display:inline-block;margin-right:8px;font-size:13px;font-weight:600;text-decoration:none;color:#fff;background:#374151;border-radius:999px;padding:5px 12px}
 .resumo{display:flex;gap:6px;flex-wrap:wrap;font-size:12px;color:var(--mut);margin-top:8px}
 .rb{background:#fff;border:1px solid var(--line);border-radius:999px;padding:2px 10px}
 section{margin-top:22px}
 h2.sec{font-size:16px;border-left:4px solid #374151;padding-left:8px;margin:18px 0 8px}
 h3{font-size:14px;color:#374151;margin:18px 0 6px}
 table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;font-size:14px}
 th,td{text-align:left;padding:7px 10px;border-bottom:1px solid var(--line)}
 th{background:#fafbfc;font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.03em}
 td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
 ol.lista{margin:6px 0;padding-left:20px} ol.lista li{margin:4px 0;font-size:14px}
 .lista .v{display:inline-block;min-width:64px;font-weight:700;color:#1f7a44;font-variant-numeric:tabular-nums}
 .lista .ed{font-size:11px;color:var(--mut);background:#eef1f4;border-radius:5px;padding:1px 6px;margin-right:4px}
 .destaque{background:#fff8ec;border:1px solid #f3dca6;border-radius:12px;padding:12px 14px}
 .destaque h3{margin-top:0;color:#9a6b00}
 .card{background:var(--card);border:1px solid var(--line);border-left:6px solid var(--mut);border-radius:12px;padding:16px;margin:16px 0;box-shadow:0 1px 3px rgba(0,0,0,.05)}
 .card.quente{border-left-color:var(--q)} .card.fria{border-left-color:var(--f)}
 .card header{display:flex;gap:10px;align-items:flex-start}
 .num{font-weight:700;color:var(--mut);font-size:13px;padding-top:3px}
 .meta{flex:1} .meta h2{margin:0;font-size:17px;line-height:1.3}
 .src{color:var(--mut);font-size:12px;margin-top:3px}
 .badges{display:flex;flex-direction:column;gap:5px;align-items:flex-end}
 .badge{font-size:10px;font-weight:700;border-radius:999px;padding:3px 9px;white-space:nowrap}
 .t-quente{background:#fde2e2;color:var(--q)} .t-fria{background:#e1edf8;color:var(--f)}
 .c-alta{background:#e3f6e8;color:#1f7a44} .c-media{background:#fcefd6;color:#9a6b00} .c-baixa{background:#f0e2f6;color:#7a2f9a}
 .lf{background:#fafbfc;border:1px solid var(--line);border-radius:8px;padding:9px 11px;font-size:14px}
 details{margin:10px 0;border-top:1px dashed var(--line);padding-top:7px}
 summary{cursor:pointer;font-weight:600;font-size:13px;color:#374151}
 .materia h4{margin:12px 0 2px;font-size:12px;color:var(--mut);text-transform:uppercase}
 .materia p{margin:4px 0;font-size:14px}
 .tags{margin:12px 0 2px;display:flex;gap:5px;flex-wrap:wrap}
 .tag{background:#eef1f4;border-radius:6px;padding:2px 8px;font-size:11px;color:#374151}
 .lacunas{margin:6px 0;padding-left:20px;color:#8a4b00;font-size:13px}
</style></head><body><div class="wrap">
<div class="top"><h1>Painel JR <small style="color:var(--mut);font-weight:400">demanda + rascunhos</small></h1>
<nav><a href="#demanda">📊 Demanda</a><a href="#leves">★ Leves</a><a href="#rascunhos">📝 Rascunhos (' . $nRasc . ')</a></nav>
<div class="resumo">' . $cob . '</div></div>

<section id="demanda"><h2 class="sec">📊 Perfil de demanda — ' . $nSinal . ' matérias mais lidas (GA4)</h2>'
. $kw . $ed . $cid . $gan . '</section>

<section id="leves"><h2 class="sec">★ Pautas leves que performaram</h2>
<div class="destaque"><h3>Feel-good · bicho · economia/desenvolvimento de SC — sem crime/tragédia</h3>' . $leves . '</div>
<h3>Top 20 geral (todas as pautas)</h3>' . $top . '</section>

<section id="rascunhos"><h2 class="sec">📝 Rascunhos editoriais (cérebro JR)</h2>' . $cards . '</section>

</div></body></html>';
    }
}
