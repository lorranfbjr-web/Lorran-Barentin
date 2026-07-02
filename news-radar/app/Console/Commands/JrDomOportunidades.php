<?php

namespace App\Console\Commands;

use App\Services\Jr\DomScorer;
use App\Services\Jr\RankingExibicao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FASE 2+3 — Radar de Oportunidades. Roda o DomScorer (Sonnet, separado do juiz)
 * sobre os atos de jr_dom_atos ainda não pontuados e grava noticiabilidade,
 * gancho, o-que-apurar e flags. Em seguida (re)gera a página passiva
 * public/oportunidades.html (mobile-first, V5.1), sem ping.
 *
 * ⚖️ Pontua como EDITOR (noticiabilidade), não auditor de valor. Saída = LEAD
 * pra apurar, nunca acusação. ISOLADO: não toca em juiz/radar/captura.
 */
class JrDomOportunidades extends Command
{
    protected $signature = 'jr:dom-oportunidades '
        . '{--limit=0 : Máx. de atos a pontuar nesta execução (0 = todos os pendentes)} '
        . '{--lote= : Atos por chamada LLM (default config dom.scoring.lote)} '
        . '{--model= : Modelo do scoring (default config dom.scoring.modelo — trocável)} '
        . '{--force : Re-pontua mesmo quem já tem score} '
        . '{--min-score= : Com --force, re-pontua só atos com score_pauta >= N (ex.: re-scorar o radar)} '
        . '{--ids= : Re-pontua só estes ato_id (lista separada por vírgula) — re-score cirúrgico} '
        . '{--render-only : Só regenera a página, sem pontuar}';

    protected $description = 'Radar de Oportunidades: pontua atos do DOM/SC com Sonnet (noticiabilidade) e gera /oportunidades.';

    public function handle(): int
    {
        if ($this->option('render-only')) {
            $caminho = $this->renderizar();
            $this->info("Página regenerada: {$caminho}");

            return self::SUCCESS;
        }

        $cfg = config('dom.scoring');
        $lote = (int) ($this->option('lote') ?: $cfg['lote']);
        $cap = (int) $cfg['cap_chamadas'];
        $scorer = new DomScorer($this->option('model') ?: null);

        $idsAlvo = array_values(array_filter(array_map('intval',
            preg_split('/\s*,\s*/', (string) $this->option('ids'), -1, PREG_SPLIT_NO_EMPTY))));

        $q = DB::table('jr_dom_atos');
        if ($idsAlvo) {
            // re-score cirúrgico: só estes atos, com ou sem score (ignora --force/whereNull)
            $q->whereIn('ato_id', $idsAlvo);
        } elseif (! $this->option('force')) {
            $q->whereNull('score_pauta');
            // SÓ PRA FRENTE: abandona o backlog histórico — não gasta LLM scorando
            // ato velho. O radar é "daqui pra frente"; histórico ingerido fica sem
            // score e fora do radar. forward_dias=0 desliga o piso (scora tudo).
            $fwd = (int) config('dom.scoring.forward_dias', 45);
            if ($fwd > 0) {
                $q->where('data_pub', '>=', now()->subDays($fwd)->toDateString());
            }
        }
        if ($this->option('min-score') !== null && $this->option('min-score') !== '') {
            // re-scoring dirigido (ex.: só o radar ≥40) — exige --force pra fazer sentido
            $q->where('score_pauta', '>=', (int) $this->option('min-score'));
        }
        $limit = (int) $this->option('limit');
        // mais recentes primeiro (sem viés de noticiabilidade na seleção)
        $q->orderByDesc('data_pub')->orderByDesc('ato_id');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $pendentes = $q->get(['ato_id', 'municipio', 'orgao', 'categoria', 'modalidade', 'valor', 'titulo', 'objeto_limpo', 'texto_bruto']);

        if ($pendentes->isEmpty()) {
            $this->info('Nada pra pontuar. Gerando página…');
            $this->renderizar();

            return self::SUCCESS;
        }

        $lotes = $pendentes->chunk($lote);
        if ($lotes->count() > $cap) {
            $this->warn(sprintf('Plano: %d chamadas > cap %d. Use --limit pra cobrir em partes.', $lotes->count(), $cap));
        }

        $this->info(sprintf('Pontuando %d atos em %d chamadas (modelo %s, lote %d)…',
            $pendentes->count(), $lotes->count(), $scorer->modelo(), $lote));

        $bar = $this->output->createProgressBar($lotes->count());
        $bar->start();
        $t0 = microtime(true);
        $ok = 0;
        $erros = 0;
        $chamadas = 0;
        foreach ($lotes as $chunk) {
            if ($chamadas >= $cap) {
                $this->newLine();
                $this->warn("Cap de {$cap} chamadas atingido — parando.");
                break;
            }
            $chamadas++;
            $itens = $chunk->map(fn ($r) => [
                'ato_id' => $r->ato_id,
                'municipio' => $r->municipio,
                'orgao' => $r->orgao,
                'categoria' => $r->categoria,
                'modalidade' => $r->modalidade,
                'valor' => $r->valor,
                'titulo' => $r->titulo,
                'objeto_limpo' => $r->objeto_limpo,
                'texto' => $r->texto_bruto,
            ])->all();

            try {
                $vereditos = $scorer->pontuarLote($itens);
                foreach ($vereditos as $atoId => $v) {
                    $upd = [
                        'score_pauta' => $v['score_pauta'],
                        'tipo' => $v['tipo'],
                        'gancho_curto' => $v['gancho_curto'],
                        'gancho' => $v['gancho'],
                        'tipo_de_gancho' => $v['tipo_de_gancho'],
                        'o_que_apurar' => json_encode($v['o_que_apurar'], JSON_UNESCAPED_UNICODE),
                        'angulo_sugerido' => $v['angulo_sugerido'],
                        'flags' => json_encode($v['flags'], JSON_UNESCAPED_UNICODE),
                        'scored_model' => $scorer->modelo(),
                        'scored_at' => now(),
                        'updated_at' => now(),
                    ];
                    // só sobrescreve o objeto_limpo (heurístico) se o Sonnet poliu de fato
                    if (! empty($v['objeto_limpo'])) {
                        $upd['objeto_limpo'] = $v['objeto_limpo'];
                    }
                    DB::table('jr_dom_atos')->where('ato_id', $atoId)->update($upd);
                    $ok++;
                }
            } catch (\Throwable $e) {
                $erros++;
                $this->newLine();
                $this->warn('Lote falhou: ' . mb_substr($e->getMessage(), 0, 160));
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Pontuados: %d atos OK, %d lotes com erro.', $ok, $erros));

        // B5 — observabilidade: uma linha por ciclo (o watchdog lê daqui)
        \App\Services\Jr\CivicoScoringLog::registrar('dom', [
            'chamadas' => $chamadas,
            'scorados' => $ok,
            'falhas' => $erros,
            'pendentes_apos' => DB::table('jr_dom_atos')->whereNull('score_pauta')
                ->where('created_at', '>=', now()->subDays(7))->count(),
            'modelo' => $scorer->modelo(),
            'duracao_ms' => (int) ((microtime(true) - $t0) * 1000),
        ]);

        $caminho = $this->renderizar();
        $this->info("Página: {$caminho}");

        return self::SUCCESS;
    }

    /**
     * (Re)gera public/oportunidades.html — passiva, mobile-first, V5.1, com os
     * dados embutidos como JSON pra ordenação/filtro no cliente.
     */
    private function renderizar(): string
    {
        // união 3-pernas (frescos ∪ interesse ∪ top) — fix de recência: item de
        // ontem/hoje e de cidade de interesse SEMPRE entra na página.
        $atos = RankingExibicao::coletar('jr_dom_atos',
            fn ($q) => $q->whereNotNull('score_pauta')->where('score_pauta', '>=', 40),
            500);

        $dados = $atos->map(fn ($a) => [
            'municipio' => $a->municipio,
            'orgao' => $a->orgao,
            'categoria' => $a->categoria,
            'modalidade' => $a->modalidade,
            'objeto' => $a->objeto_limpo ?: $a->objeto,
            'valor' => $a->valor !== null ? (float) $a->valor : null,
            'fornecedor' => $a->fornecedor,
            'data_pub' => $a->data_pub,
            'url_fonte' => $a->url_fonte,
            'url_pdf' => $a->url_pdf,
            'score' => (int) $a->score_pauta,
            'gancho' => $a->gancho,
            'tipo_gancho' => $a->tipo_de_gancho,
            'apurar' => json_decode($a->o_que_apurar ?: '[]', true),
            'angulo' => $a->angulo_sugerido,
            'flags' => json_decode($a->flags ?: '[]', true),
            // exibição: tier + vago + fresco + score_x ('score' segue cru)
        ] + RankingExibicao::avaliar((int) $a->score_pauta, $a->municipio, $a->data_pub, $a->objeto_limpo ?: $a->objeto))->values()->all();

        $totalScored = DB::table('jr_dom_atos')->whereNotNull('score_pauta')->count();
        $totalAtos = DB::table('jr_dom_atos')->count();
        $municipios = DB::table('jr_dom_atos')->whereNotNull('municipio')->distinct()->count('municipio');
        $geradoEm = now()->format('d/m/Y H:i');

        $html = $this->montarHtml($dados, [
            'flagged' => count($dados),
            'scored' => $totalScored,
            'total' => $totalAtos,
            'municipios' => $municipios,
            'gerado' => $geradoEm,
        ]);

        $caminho = public_path('oportunidades.html');
        file_put_contents($caminho, $html);

        return $caminho;
    }

    private function montarHtml(array $dados, array $stats): string
    {
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $s = $stats;

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Radar de Oportunidades · DOM/SC</title>
<style>
  :root{
    --navy:#0D2481; --red:#E63946; --green:#2D6A4F; --amber:#D4A373;
    --ink:#16181d; --muted:#6b7280; --line:#e6e8ee; --bg:#f5f6fa; --card:#fff;
  }
  *{box-sizing:border-box} html{-webkit-text-size-adjust:100%}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
       background:var(--bg);color:var(--ink);line-height:1.45}
  header{background:var(--navy);color:#fff;padding:18px 16px 14px}
  header h1{margin:0;font-size:19px;font-weight:800;letter-spacing:-.2px}
  header .sub{font-size:12px;opacity:.82;margin-top:3px}
  .disc{background:#fff7ed;border-bottom:1px solid #fed7aa;color:#9a3412;
        font-size:11.5px;padding:8px 16px;line-height:1.4}
  .stats{display:flex;gap:14px;flex-wrap:wrap;padding:10px 16px;background:var(--navy);
         color:#fff;font-size:11.5px;border-top:1px solid rgba(255,255,255,.12)}
  .stats b{font-size:15px;display:block;font-weight:800}
  .controls{position:sticky;top:0;z-index:5;background:var(--card);border-bottom:1px solid var(--line);
            padding:10px 12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .controls input,.controls select{font:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--line);
            border-radius:9px;background:#fff;color:var(--ink)}
  .controls input[type=search]{flex:1 1 160px;min-width:130px}
  .count{font-size:12px;color:var(--muted);margin-left:auto}
  main{padding:12px;max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:11px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;
        box-shadow:0 1px 2px rgba(16,24,40,.04)}
  .card .top{display:flex;align-items:flex-start;gap:10px}
  .score{flex:0 0 auto;width:46px;height:46px;border-radius:11px;display:flex;align-items:center;justify-content:center;
         font-weight:800;font-size:17px;color:#fff;background:var(--green)}
  .score.hi{background:var(--red)} .score.mid{background:var(--navy)} .score.lo{background:var(--amber)}
  .hd{flex:1;min-width:0}
  .muni{font-weight:800;font-size:15px}
  .org{font-size:12px;color:var(--muted)}
  .obj{margin:9px 0 6px;font-size:14.5px;font-weight:600}
  .meta{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0}
  .pill{font-size:11px;padding:3px 9px;border-radius:999px;background:#eef1f8;color:var(--navy);font-weight:600}
  .pill.mod{background:#fdecef;color:var(--red)}
  .pill.val{background:#e8f3ee;color:var(--green);font-weight:800}
  .gancho{font-size:13.5px;background:#f8f9fc;border-left:3px solid var(--navy);padding:8px 11px;border-radius:0 9px 9px 0;margin:8px 0}
  .gancho .lab{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;display:block;margin-bottom:2px}
  .tg{font-size:11px;color:var(--amber);font-weight:700;text-transform:uppercase;letter-spacing:.3px}
  details{margin-top:6px} summary{cursor:pointer;font-size:12.5px;color:var(--navy);font-weight:700;list-style:none}
  summary::-webkit-details-marker{display:none} summary::before{content:"▸ "}
  details[open] summary::before{content:"▾ "}
  .apurar{margin:8px 0 4px;padding-left:18px} .apurar li{font-size:13px;margin:3px 0}
  .angulo{font-size:13px;font-style:italic;color:#334;margin:6px 0}
  .src{display:inline-block;margin-top:8px;font-size:12.5px;font-weight:700;color:#fff;background:var(--navy);
       padding:7px 13px;border-radius:9px;text-decoration:none}
  .src.pdf{background:#fff;color:var(--navy);border:1px solid var(--navy);margin-left:6px}
  .empty{text-align:center;color:var(--muted);padding:40px 16px;font-size:14px}
  .sep{font-size:12px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.5px;margin:14px 2px 2px;padding-top:8px;border-top:1px dashed var(--line)}
  footer{padding:18px 16px 40px;text-align:center;color:var(--muted);font-size:11px}
</style>
</head>
<body>
<header>
  <h1>📡 Radar de Oportunidades · DOM/SC</h1>
  <div class="sub">Atos do Diário Oficial dos Municípios de SC que um editor levantaria a sobrancelha · PoC</div>
</header>
<div class="disc">⚖️ Cada item é um <b>FATO</b> público (licitação/compra) e um <b>LEAD pra apurar</b> — não uma acusação. "Superfaturado/irregular" é tese que só a apuração humana confirma.</div>
<div class="stats">
  <div><b>{$s['flagged']}</b> possíveis pautas (≥40)</div>
  <div><b>{$s['scored']}</b> atos analisados</div>
  <div><b>{$s['total']}</b> atos ingeridos</div>
  <div><b>{$s['municipios']}</b> municípios</div>
</div>
<div class="controls">
  <input type="search" id="q" placeholder="🔎 município, objeto, fornecedor…">
  <select id="cid"><option value="">Todas as cidades</option><option value="1">⭐ Cidades de interesse</option></select>
  <select id="mod"><option value="">Toda modalidade</option></select>
  <select id="ord">
    <option value="score">Ordenar: noticiabilidade</option>
    <option value="valor">Ordenar: valor</option>
    <option value="data">Ordenar: data</option>
    <option value="muni">Ordenar: município</option>
  </select>
  <select id="vmin">
    <option value="0">Qualquer valor</option>
    <option value="10000">≥ R$ 10 mil</option>
    <option value="50000">≥ R$ 50 mil</option>
    <option value="100000">≥ R$ 100 mil</option>
    <option value="500000">≥ R$ 500 mil</option>
  </select>
  <span class="count" id="count"></span>
</div>
<main id="lista"></main>
<footer>Gerado em {$s['gerado']} · fonte: diariomunicipal.sc.gov.br (FECAM/CIGA) · Jornal Razão — uso editorial interno</footer>
<script>
const DADOS = {$json};
const EIXOS = {1:"objeto chama atenção",2:"modalidade (dispensa/inexig.)",3:"valor desproporcional",4:"sensibilidade política",5:"padrão/fornecedor recorrente",6:"interesse local/humano"};
const fmtV = v => v==null ? "valor n/d" : "R$ "+v.toLocaleString("pt-BR",{minimumFractionDigits:2,maximumFractionDigits:2});
const fmtD = d => { if(!d) return ""; const p=d.split("-"); return p.length===3 ? p[2]+"/"+p[1] : d; };
const esc = s => (s||"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const scoreCls = s => s>=80?"hi":s>=60?"mid":s>=40?"":"lo";

// popular filtro de modalidade
const mods=[...new Set(DADOS.map(d=>d.modalidade).filter(Boolean))].sort();
const modSel=document.getElementById("mod");
mods.forEach(m=>{const o=document.createElement("option");o.value=m;o.textContent=m;modSel.appendChild(o)});

function card(d){
  const flags=(d.flags||[]).map(f=>'<span class="pill">'+esc(EIXOS[f]||("eixo "+f))+'</span>').join("");
  const apurar=(d.apurar||[]).map(b=>'<li>'+esc(b)+'</li>').join("");
  const val = d.valor!=null ? '<span class="pill val">'+fmtV(d.valor)+'</span>' : '';
  const mod = d.modalidade ? '<span class="pill mod">'+esc(d.modalidade)+'</span>' : '';
  return '<div class="card">'+
    '<div class="top">'+
      '<div class="score '+scoreCls(d.score)+'">'+d.score+'</div>'+
      '<div class="hd"><div class="muni">'+esc(d.municipio||"—")+'</div>'+
      '<div class="org">'+esc(d.orgao||"")+' · '+esc(d.categoria||"")+' · '+fmtD(d.data_pub)+'</div></div>'+
    '</div>'+
    (d.tipo_gancho?'<div class="tg">'+esc(d.tipo_gancho)+'</div>':'')+
    '<div class="obj">'+esc(d.objeto||d.gancho||"")+'</div>'+
    '<div class="meta">'+val+mod+'<span class="pill">'+esc(d.categoria||"")+'</span></div>'+
    (d.gancho?'<div class="gancho"><span class="lab">por que vira pauta</span>'+esc(d.gancho)+'</div>':'')+
    (apurar?'<details><summary>o que apurar</summary><ul class="apurar">'+apurar+'</ul>'+
      (d.angulo?'<div class="angulo">Ângulo: '+esc(d.angulo)+'</div>':'')+
      (flags?'<div class="meta">'+flags+'</div>':'')+'</details>':'')+
    '<div><a class="src" href="'+esc(d.url_fonte)+'" target="_blank" rel="noopener">Ver ato no DOM</a>'+
      (d.url_pdf?'<a class="src pdf" href="'+esc(d.url_pdf)+'" target="_blank" rel="noopener">PDF</a>':'')+'</div>'+
  '</div>';
}

function render(){
  const q=document.getElementById("q").value.toLowerCase().trim();
  const mod=document.getElementById("mod").value;
  const ord=document.getElementById("ord").value;
  const vmin=parseFloat(document.getElementById("vmin").value)||0;
  const cid=document.getElementById("cid").value;
  let arr=DADOS.filter(d=>{
    if(cid && !d.tier) return false;
    if(mod && d.modalidade!==mod) return false;
    if(vmin && !(d.valor>=vmin)) return false;
    if(q){ const hay=((d.municipio||"")+" "+(d.orgao||"")+" "+(d.objeto||"")+" "+(d.fornecedor||"")+" "+(d.gancho||"")+" "+(d.tipo_gancho||"")).toLowerCase(); if(!hay.includes(q)) return false; }
    return true;
  });
  arr.sort((a,b)=>{
    if(ord==="valor") return (b.valor||0)-(a.valor||0);
    if(ord==="data") return (b.data_pub||"").localeCompare(a.data_pub||"");
    if(ord==="muni") return (a.municipio||"").localeCompare(b.municipio||"");
    return b.score_x-a.score_x;
  });
  document.getElementById("count").textContent=arr.length+" itens";
  let html="";
  if(ord==="score"){// cara do gol: frescos (48h) primeiro, resto vira Arquivo
    const fresco=arr.filter(d=>d.fresco), velho=arr.filter(d=>!d.fresco);
    html=(fresco.length?'<div class="sep">🔥 Últimas 48h</div>'+fresco.map(card).join(""):"")
        +(velho.length?'<div class="sep">📁 Arquivo</div>'+velho.map(card).join(""):"");
  }else{html=arr.map(card).join("");}
  document.getElementById("lista").innerHTML = arr.length ? html :
    '<div class="empty">Nenhum ato bate os filtros.</div>';
}
["q","cid","mod","ord","vmin"].forEach(id=>document.getElementById(id).addEventListener("input",render));
render();
</script>
</body>
</html>
HTML;
    }
}
