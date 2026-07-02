<?php

namespace App\Http\Controllers;

use App\Services\Jr\DomConector;
use App\Services\Jr\DomEntidades;
use App\Services\Jr\DomGeografia;
use App\Services\Jr\RankingExibicao;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Radar de Oportunidades DOM/SC — 3 páginas que LEEM jr_dom_atos ao vivo
 * (server-rendered, sem LLM no request), então enchem sozinhas conforme o
 * scoring de fundo termina:
 *   1. /dom-todos   — firehose cronológico cru (TUDO, sem nota/análise)
 *   2. /dom-radar   — radar enxuto (cidade · o que é · por que vira pauta; resto em spoiler)
 *   3. /dom-busca   — busca dirigida AO VIVO no Solr (município · modalidade · período)
 *
 * ISOLADO: subsistema DOM/SC, não toca juiz/radar editorial/captura/dispatcher.
 * ⚖️ Cada ato = FATO público + LEAD pra apurar, NUNCA acusação.
 */
class DomController extends Controller
{
    /** Modalidade do formulário -> categoria do DOM/SC (+ filtro fino opcional). */
    private const MODALIDADES = [
        'dispensa'         => ['cat' => 'Dispensas', 'mod' => 'dispensa'],
        'inexigibilidade'  => ['cat' => 'Dispensas', 'mod' => 'inexigibilidade'],
        'pregao'           => ['cat' => 'Licitações', 'mod' => 'pregão'],
        'contrato'         => ['cat' => 'Contratos', 'mod' => null],
        'todas'            => ['cat' => null, 'mod' => null],
    ];

    /**
     * CINCATARINA — Consórcio Interfederativo Santa Catarina (codigoEntidade 363),
     * o maior gastador do estado. Já é capturado pelo crawler do DOM (executivo);
     * aqui só damos DESTAQUE/pin (badge no card + filtro). Casa pelo órgão (o snippet
     * grava o nome do consórcio, não o código).
     */
    private const CINCA_ORGAO = 'Interfederativo Santa Catarina';

    private function ehCinca(?string $orgao): bool
    {
        return $orgao !== null && mb_stripos($orgao, self::CINCA_ORGAO) !== false;
    }

    // ───────────────────────── dedup + fornecedor (OBJ4/OBJ6) ─────────────────────────

    /** Assinatura de conteúdo p/ colapsar republicações (mesma entidade+objeto+data). */
    private function assinatura(?string $orgao, ?string $municipio, ?string $modalidade, ?string $objeto, ?string $data): string
    {
        $norm = fn (?string $s) => preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $s)));

        return implode('|', [$norm($orgao ?: $municipio), $norm($modalidade), $norm($objeto), (string) $data]);
    }

    /**
     * Colapsa itens repetidos (OBJ4). Mantém o 1º (mais recente, já ordenado) e
     * conta as republicações em '_reps'. $sig devolve a assinatura de cada item.
     *
     * @param  array<int,array>  $itens
     * @return array<int,array>  deduplicado, cada item com '_reps' (1 = único)
     */
    private function colapsar(array $itens, callable $sig): array
    {
        $vistos = [];   // assinatura => índice no $out
        $out = [];
        foreach ($itens as $it) {
            $k = $sig($it);
            if (isset($vistos[$k])) {
                $out[$vistos[$k]]['_reps']++;

                continue;
            }
            $it['_reps'] = 1;
            $vistos[$k] = count($out);
            $out[] = $it;
        }

        return $out;
    }

    /**
     * Mapa fornecedor (normalizado) => nº de MUNICÍPIOS distintos em que aparece
     * (OBJ6, best-effort). Só conta fornecedor extraído (heurístico — costuma vir
     * nulo); serve pra sinalizar "fornecedor recorrente", nunca como prova.
     *
     * @return array<string,int>
     */
    private function fornecedorRecorrencia(): array
    {
        $linhas = DB::table('jr_dom_atos')
            ->whereNotNull('fornecedor')->where('fornecedor', '!=', '')
            ->whereNotNull('municipio')
            ->get(['fornecedor', 'municipio']);

        $mapa = []; // fornNorm => [municipios distintos]
        foreach ($linhas as $r) {
            $k = $this->normFornecedor($r->fornecedor);
            if ($k === '') {
                continue;
            }
            $mapa[$k][$r->municipio] = true;
        }

        return array_map('count', $mapa);
    }

    private function normFornecedor(string $s): string
    {
        $s = mb_strtoupper(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        // tira sufixos societários pra agrupar a mesma empresa escrita de formas diferentes
        $s = preg_replace('/[\.,]/', '', $s);

        return trim((string) $s);
    }

    // ───────────────────────── PÁGINA 1 — Todos ─────────────────────────

    public function todos()
    {
        $atos = DB::table('jr_dom_atos')
            ->orderByDesc('data_pub')->orderByDesc('ato_id')
            ->limit(2000)
            ->get(['municipio', 'orgao', 'categoria', 'modalidade', 'objeto_limpo', 'valor', 'data_pub', 'url_fonte', 'url_pdf']);

        $total = DB::table('jr_dom_atos')->count();

        // OBJ4 — colapsa republicações (mesma entidade+objeto+data) num só item.
        $itens = $atos->map(fn ($a) => (array) $a)->all();
        $itens = $this->colapsar($itens, fn ($a) => $this->assinatura(
            $a['orgao'] ?? null, $a['municipio'] ?? null, $a['modalidade'] ?? null, $a['objeto_limpo'] ?? null, $a['data_pub'] ?? null));
        $mostrados = count($itens);

        $linhas = collect($itens)->map(function ($a) {
            $val = $a['valor'] !== null ? 'R$ ' . number_format((float) $a['valor'], 0, ',', '.') : '—';
            $mod = $a['modalidade'] ?: ($a['categoria'] ?: '—');
            $reg = DomGeografia::regiao($a['municipio']);
            $muni = '<span class="mn">' . e($a['municipio'] ?: '—') . '</span>'
                . ($reg ? '<span class="reg">' . e($reg) . '</span>' : '');
            $objFull = (string) ($a['objeto_limpo'] ?: '—');
            $reps = ($a['_reps'] ?? 1) > 1
                ? '<span class="reps" title="republicado ' . (int) $a['_reps'] . '×">×' . (int) $a['_reps'] . '</span>' : '';
            // OBJ5 — objeto truncado por CSS (ellipsis); clique/hover revela o inteiro.
            $obj = '<span class="objt" title="' . $this->attr($objFull) . '">' . e($objFull) . '</span>' . $reps;

            return '<tr>'
                . '<td class="nw dt">' . $this->fmtData($a['data_pub']) . '</td>'
                . '<td class="muni">' . $muni . '</td>'
                . '<td class="modc"><span class="mod">' . e($mod) . '</span></td>'
                . '<td class="obj">' . $obj . '</td>'
                . '<td class="nw val">' . $val . '</td>'
                . '<td class="nw src"><a href="' . e($a['url_fonte']) . '" target="_blank" rel="noopener">ato</a>'
                . ($a['url_pdf'] ? '<a href="' . e($a['url_pdf']) . '" target="_blank" rel="noopener">pdf</a>' : '') . '</td>'
                . '</tr>';
        })->implode('');

        $body = <<<HTML
<div class="bar">
  <input type="search" id="f" placeholder="🔎 filtrar por município, objeto, modalidade…">
  <span class="muted" id="cnt">{$mostrados} de {$total} atos</span>
</div>
<div class="muted small">Toque/clique num objeto pra ver o texto inteiro. Republicações idênticas vêm colapsadas (×N).</div>
<table id="t" class="firehose">
  <thead><tr><th class="dt">Data</th><th>Município · região</th><th>Modalidade</th><th>Objeto</th><th class="val">Valor</th><th class="src">Fonte</th></tr></thead>
  <tbody>{$linhas}</tbody>
</table>
<script>
const f=document.getElementById('f'),rows=[...document.querySelectorAll('#t tbody tr')],cnt=document.getElementById('cnt');
f.addEventListener('input',()=>{const q=f.value.toLowerCase().trim();let n=0;
  rows.forEach(r=>{const v=!q||r.textContent.toLowerCase().includes(q);r.style.display=v?'':'none';if(v)n++});
  cnt.textContent=n+' atos';});
// OBJ5 — tap/click no objeto alterna entre truncado e inteiro
document.querySelectorAll('#t .obj .objt').forEach(el=>el.addEventListener('click',()=>el.classList.toggle('open')));
</script>
HTML;

        return $this->chrome('todos', 'Todos os atos', 'Firehose cronológico do DOM/SC — objeto legível, sem nota, mais recente primeiro', $body);
    }

    // ───────────────────────── PÁGINA 2 — Radar enxuto ─────────────────────────

    public function radar()
    {
        // união 3-pernas (frescos ∪ interesse ∪ top) — fix de recência: item de
        // ontem/hoje e de cidade de interesse SEMPRE chega ao JSON.
        $atos = RankingExibicao::coletar('jr_dom_atos',
            fn ($q) => $q->whereNotNull('score_pauta')->where('score_pauta', '>=', 40),
            500);

        // OBJ6 — recorrência de fornecedor (best-effort, heurístico): nº de
        // municípios distintos em que o fornecedor aparece em TODA a base.
        $recorrencia = $this->fornecedorRecorrencia();

        $dados = $atos->map(function ($a) use ($recorrencia) {
            $fornReps = null;
            if ($a->fornecedor) {
                $k = $this->normFornecedor($a->fornecedor);
                if ($k !== '' && ($recorrencia[$k] ?? 0) >= 2) {
                    $fornReps = $recorrencia[$k];
                }
            }

            // exibição: tier + vago + fresco + score_x ('score' segue cru)
            $rx = RankingExibicao::avaliar((int) $a->score_pauta, $a->municipio, $a->data_pub, $a->objeto_limpo ?: $a->objeto);

            return [
                'municipio' => $a->municipio,
                'regiao' => DomGeografia::regiao($a->municipio),
                'tier' => $rx['tier'],
                'vago' => $rx['vago'],
                'fresco' => $rx['fresco'],
                'score_x' => $rx['score_x'],
                'orgao' => $a->orgao,
                'categoria' => $a->categoria,
                'modalidade' => $a->modalidade,
                // objeto_limpo (Sonnet polido pros pontuados; heurístico de fallback)
                'objeto' => $a->objeto_limpo ?: $a->objeto,
                'valor' => $a->valor !== null ? (float) $a->valor : null,
                'fornecedor' => $a->fornecedor,
                'forn_reps' => $fornReps, // nº municípios se recorrente (>=2), senão null
                'cinca' => $this->ehCinca($a->orgao), // 🏛️ CINCATARINA (maior gastador do estado)
                'data_pub' => $a->data_pub,
                'texto' => (string) ($a->texto_bruto ?: ''),
                'url_fonte' => $a->url_fonte,
                'url_pdf' => $a->url_pdf,
                'score' => (int) $a->score_pauta,
                'tipo' => $a->tipo, // dual-lens: fiscalizacao | servico | neutro | null
                'gancho_curto' => $a->gancho_curto,
                'gancho' => $a->gancho,
                'tipo_gancho' => $a->tipo_de_gancho,
                'apurar' => json_decode($a->o_que_apurar ?: '[]', true),
                'angulo' => $a->angulo_sugerido,
                'flags' => json_decode($a->flags ?: '[]', true),
            ];
        })->values()->all();

        // OBJ4 — colapsa republicações (mesma entidade+objeto+data)
        $dados = $this->colapsar($dados, fn ($d) => $this->assinatura(
            $d['orgao'] ?? null, $d['municipio'] ?? null, $d['modalidade'] ?? null, $d['objeto'] ?? null, $d['data_pub'] ?? null));

        $totalScored = DB::table('jr_dom_atos')->whereNotNull('score_pauta')->count();
        $total = DB::table('jr_dom_atos')->count();
        $nFisc = count(array_filter($dados, fn ($d) => $d['tipo'] === 'fiscalizacao'));
        $nServ = count(array_filter($dados, fn ($d) => $d['tipo'] === 'servico'));
        $nCinca = count(array_filter($dados, fn ($d) => $d['cinca']));
        $nInt = count(array_filter($dados, fn ($d) => $d['tier'] > 0));
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $body = <<<HTML
<div class="bar">
  <input type="search" id="q" placeholder="🔎 cidade, objeto, gancho…">
  <select id="mod"><option value="">Toda modalidade</option></select>
  <select id="ord">
    <option value="score">Noticiabilidade</option>
    <option value="valor">Valor</option>
    <option value="data">Data</option>
    <option value="muni">Município</option>
  </select>
  <select id="vmin">
    <option value="0">Qualquer valor</option><option value="10000">≥ R$ 10 mil</option>
    <option value="50000">≥ R$ 50 mil</option><option value="100000">≥ R$ 100 mil</option><option value="500000">≥ R$ 500 mil</option>
  </select>
  <span class="muted" id="count"></span>
</div>
<div class="lens">
  <button type="button" class="lb on" data-int="">Todas as cidades</button>
  <button type="button" class="lb" data-int="1">⭐ Interesse <b>{$nInt}</b></button>
</div>
<div class="lens">
  <button type="button" class="lb on" data-tipo="">Tudo</button>
  <button type="button" class="lb" data-tipo="fiscalizacao">🔴 Fiscalização <b>{$nFisc}</b></button>
  <button type="button" class="lb" data-tipo="servico">🟢 Serviço <b>{$nServ}</b></button>
  <button type="button" class="lb cinca" id="bcinca">🏛️ CINCATARINA <b>{$nCinca}</b></button>
  <label class="fchk"><input type="checkbox" id="fornrec"> só fornecedor recorrente</label>
</div>
<div class="muted small">{$totalScored} atos analisados · {$total} ingeridos · a página enche sozinha conforme o scoring termina</div>
<main id="lista"></main>
<script>
const DADOS={$json};
const EIXOS={1:"objeto chama atenção",2:"modalidade (dispensa/inexig.)",3:"valor desproporcional",4:"sensibilidade política",5:"padrão/fornecedor recorrente",6:"interesse local/humano"};
const fmtV=v=>v==null?"valor n/d":"R$ "+v.toLocaleString("pt-BR",{minimumFractionDigits:2,maximumFractionDigits:2});
const fmtD=d=>{if(!d)return"";const p=d.split("-");return p.length===3?p[2]+"/"+p[1]:d;};
const esc=s=>(s||"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const cls=s=>s>=80?"hi":s>=60?"mid":s>=40?"":"lo";
const mods=[...new Set(DADOS.map(d=>d.modalidade).filter(Boolean))].sort();
const modSel=document.getElementById("mod");
mods.forEach(m=>{const o=document.createElement("option");o.value=m;o.textContent=m;modSel.appendChild(o)});

function card(d){
  const flags=(d.flags||[]).map(f=>'<span class="pill">'+esc(EIXOS[f]||("eixo "+f))+'</span>').join("");
  const apurar=(d.apurar||[]).map(b=>'<li>'+esc(b)+'</li>').join("");
  const val=d.valor!=null?'<span class="pill val">'+fmtV(d.valor)+'</span>':'';
  const mod=d.modalidade?'<span class="pill mod">'+esc(d.modalidade)+'</span>':'';
  // CARA (passar-o-olho): 3 linhas exatas.
  //  L1: NOTA · MUNICÍPIO · REGIÃO
  //  L2: MODALIDADE + OBJETO (objeto_limpo, o mais específico possível)
  //  L3: gancho_curto (hook provocativo de ≤8 palavras; é LEAD, não acusação)
  const reg=d.regiao?'<span class="reg"> · '+esc(d.regiao)+'</span>':'';
  const modtag=d.modalidade?'<span class="modtag">'+esc(d.modalidade)+'</span> ':'';
  const hook=d.gancho_curto||d.gancho||"";
  // OBJ6 — marcador de lente (🔴 fiscalização / 🟢 serviço) e badges
  const lens=d.tipo==='fiscalizacao'?'<span class="lens-dot" title="fiscalização — red flag a apurar">🔴</span>':(d.tipo==='servico'?'<span class="lens-dot" title="serviço / 1ª-mão">🟢</span>':'');
  const fr=d.forn_reps?'<span class="frec" title="'+esc(d.fornecedor||"")+'">👷 fornecedor recorrente · '+d.forn_reps+' municípios</span>':'';
  const cinca=d.cinca?'<span class="frec cinca" title="Consórcio Interfederativo Santa Catarina — maior gastador do estado">🏛️ CINCATARINA</span>':'';
  const reps=(d._reps>1)?'<span class="frec rep">republicado ×'+d._reps+'</span>':'';
  return '<div class="card">'+
    '<div class="face">'+
      '<div class="score '+cls(d.score)+'">'+d.score+'</div>'+
      '<div class="hd">'+
        '<div class="l1">'+lens+'<span class="muni">'+esc(d.municipio||"—")+'</span>'+reg+'</div>'+
        '<div class="l2">'+modtag+esc(d.objeto||"")+'</div>'+
        (hook?'<div class="l3">'+esc(hook)+'</div>':'')+
        ((cinca||fr||reps)?'<div class="badges">'+cinca+fr+reps+'</div>':'')+
      '</div>'+
    '</div>'+
    '<details><summary>ver detalhes</summary><div class="det">'+
      (d.tipo_gancho?'<div class="tg">'+esc(d.tipo_gancho)+'</div>':'')+
      (d.gancho?'<div class="why"><span class="lab">por que vira pauta</span>'+esc(d.gancho)+'</div>':'')+
      '<div class="meta">'+val+mod+'<span class="pill">'+esc(d.categoria||"")+'</span><span class="pill">'+fmtD(d.data_pub)+'</span></div>'+
      '<div class="org">'+esc(d.orgao||"")+'</div>'+
      (apurar?'<div class="lab">o que apurar</div><ul class="apurar">'+apurar+'</ul>':'')+
      (d.angulo?'<div class="angulo">Ângulo: '+esc(d.angulo)+'</div>':'')+
      (d.texto?'<details class="inner"><summary>texto do ato</summary><div class="txt">'+esc(d.texto)+'</div></details>':'')+
      (flags?'<div class="meta">'+flags+'</div>':'')+
      '<div class="links"><a class="src" href="'+esc(d.url_fonte)+'" target="_blank" rel="noopener">Ver ato no DOM</a>'+
        (d.url_pdf?'<a class="src pdf" href="'+esc(d.url_pdf)+'" target="_blank" rel="noopener">PDF</a>':'')+'</div>'+
    '</div></details>'+
  '</div>';
}
let tipoSel="",soCinca=false,intSel="";
function render(){
  const q=document.getElementById("q").value.toLowerCase().trim();
  const mod=document.getElementById("mod").value,ord=document.getElementById("ord").value;
  const vmin=parseFloat(document.getElementById("vmin").value)||0;
  const soForn=document.getElementById("fornrec").checked;
  let arr=DADOS.filter(d=>{
    if(tipoSel&&d.tipo!==tipoSel)return false;
    if(soCinca&&!d.cinca)return false;
    if(intSel&&!d.tier)return false;
    if(soForn&&!d.forn_reps)return false;
    if(mod&&d.modalidade!==mod)return false;
    if(vmin&&!(d.valor>=vmin))return false;
    if(q){const h=((d.municipio||"")+" "+(d.regiao||"")+" "+(d.orgao||"")+" "+(d.objeto||"")+" "+(d.gancho_curto||"")+" "+(d.gancho||"")+" "+(d.tipo_gancho||"")+" "+(d.fornecedor||"")).toLowerCase();if(!h.includes(q))return false;}
    return true;});
  arr.sort((a,b)=>{
    if(ord==="valor")return(b.valor||0)-(a.valor||0);
    if(ord==="data")return(b.data_pub||"").localeCompare(a.data_pub||"");
    if(ord==="muni")return(a.municipio||"").localeCompare(b.municipio||"");
    return b.score_x-a.score_x;});
  document.getElementById("count").textContent=arr.length+" itens";
  let html="";
  if(ord==="score"){// cara do gol: frescos (48h) primeiro, resto vira Arquivo
    const fresco=arr.filter(d=>d.fresco), velho=arr.filter(d=>!d.fresco);
    html=(fresco.length?'<div class="day-sep">🔥 Últimas 48h</div>'+fresco.map(card).join(""):"")
        +(velho.length?'<div class="day-sep">📁 Arquivo (mais antigos)</div>'+velho.map(card).join(""):"");
  }else{html=arr.map(card).join("");}
  document.getElementById("lista").innerHTML=arr.length?html:'<div class="empty">Nenhum ato bate os filtros.</div>';
}
document.querySelectorAll(".lb[data-tipo]").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll(".lb[data-tipo]").forEach(x=>x.classList.remove("on"));
  b.classList.add("on");tipoSel=b.dataset.tipo;render();}));
document.querySelectorAll(".lb[data-int]").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll(".lb[data-int]").forEach(x=>x.classList.remove("on"));
  b.classList.add("on");intSel=b.dataset.int;render();}));
// CINCATARINA é filtro de ENTIDADE (ortogonal à lente 🔴/🟢): toggle próprio.
document.getElementById("bcinca").addEventListener("click",e=>{
  soCinca=!soCinca;e.currentTarget.classList.toggle("on",soCinca);render();});
["q","mod","ord","vmin","fornrec"].forEach(id=>document.getElementById(id).addEventListener("input",render));
render();
</script>
HTML;

        return $this->chrome('radar', 'Radar', 'Possíveis pautas (noticiabilidade ≥ 40) — nota · cidade · região · o que é · gancho', $body);
    }

    // ───────────────────────── PÁGINA 3 — Busca dirigida ─────────────────────────

    public function busca(Request $request, DomConector $conector)
    {
        $municipio = trim((string) $request->query('municipio', ''));
        $codigo = (int) $request->query('codigoEntidade', 0);
        $modKey = (string) $request->query('modalidade', 'todas');
        $dias = (int) $request->query('dias', 30);
        $dias = in_array($dias, [7, 15, 30, 60], true) ? $dias : 30;
        $modCfg = self::MODALIDADES[$modKey] ?? self::MODALIDADES['todas'];

        $entidade = $codigo ? DomEntidades::find($codigo) : null;

        $resultadoHtml = '';
        if ($entidade) {
            // Filtro ESTRUTURADO por entidade (codigoEntidade) — feed RSS do DOM.
            // Escopo EXATO da entidade; nada de texto livre (sem "Itajaí genérico").
            $fim = Carbon::now();
            $ini = $fim->copy()->subDays($dias);
            $atos = $conector->buscarEntidade($codigo, $modCfg['cat'], $ini, $fim, 20);
            if ($modCfg['mod']) {
                $atos = array_filter($atos, fn ($a) => $a['modalidade'] === $modCfg['mod']);
            }
            usort($atos, fn ($a, $b) => strcmp((string) $b['data_pub'], (string) $a['data_pub']));

            // RSS rende 10/pág e paramos em 20 págs: ~200 = provável corte (entidade
            // muito ativa). Avisamos pra restringir por categoria/janela.
            $truncado = count($atos) >= 190;

            $reg = $entidade['regiao'] ? ' · ' . e($entidade['regiao']) : '';
            $cab = '<div class="enthd"><div class="entnome">' . e($entidade['nome']) . '</div>'
                . '<div class="entsub">' . e($entidade['tipo']) . ($entidade['municipio'] ? ' · ' . e($entidade['municipio']) : '') . $reg
                . ' · cód. ' . $codigo . '</div></div>';

            if (! $atos) {
                $resultadoHtml = $cab . '<div class="empty">Nenhum ato dessa entidade nos últimos ' . $dias . ' dias' . ($modKey !== 'todas' ? ' (filtro: ' . e($modKey) . ')' : '') . '.</div>';
            } else {
                $linhas = collect($atos)->map(function ($a) {
                    $val = $a['valor'] !== null ? '<span class="pill val">R$ ' . number_format((float) $a['valor'], 2, ',', '.') . '</span>' : '';
                    $mod = $a['modalidade'] ?: ($a['categoria'] ?: '');
                    $obj = e(($a['objeto_limpo'] ?? null) ?: $a['objeto'] ?: '—');
                    $texto = trim((string) ($a['texto_bruto'] ?? ''));
                    $lerCompleto = $texto !== ''
                        ? '<details class="inner"><summary>ler completo</summary><div class="txt">' . e($texto) . '</div></details>'
                        : '<div class="muted small">Texto integral não disponível nesta listagem.</div>';

                    return '<div class="rcard">'
                        . '<div class="rmeta">'
                            . '<span class="nw">' . $this->fmtData($a['data_pub']) . '</span>'
                            . '<span class="mod">' . e($mod) . '</span>'
                            . $val
                        . '</div>'
                        . '<div class="robj">' . $obj . '</div>'
                        . ($a['orgao'] ? '<div class="rorg">' . e($a['orgao']) . '</div>' : '')
                        . $lerCompleto
                        . '<div class="links"><a class="src" href="' . e($a['url_fonte']) . '" target="_blank" rel="noopener">Ver ato no DOM</a>'
                        . ($a['url_pdf'] ? '<a class="src pdf" href="' . e($a['url_pdf']) . '" target="_blank" rel="noopener">PDF</a>' : '') . '</div>'
                        . '</div>';
                })->implode('');
                $aviso = $truncado
                    ? '<div class="muted small">⚠️ Entidade muito ativa — mostrando os ~' . count($atos) . ' atos mais recentes (pode haver mais na janela). Restrinja por categoria ou janela menor.</div>'
                    : '';
                $resultadoHtml = $cab
                    . '<div class="muted small">' . count($atos) . ' atos · últimos ' . $dias . ' dias'
                    . ($modKey !== 'todas' ? ' · ' . e($modKey) : '') . ' · objeto legível + “ler completo” (texto integral, custo zero)</div>'
                    . $aviso
                    . '<main class="rlist">' . $linhas . '</main>';
            }
        } elseif ($municipio !== '') {
            $resultadoHtml = '<div class="muted small">Escolha a <b>entidade</b> (Prefeitura, Câmara, Fundo…) e clique em Buscar.</div>';
        }

        // ── selects: município → entidade (codigoEntidade) ──
        $municipios = DomEntidades::municipios();
        $munOpts = '<option value="">— escolha o município —</option>';
        foreach ($municipios as $m) {
            $munOpts .= '<option value="' . $this->attr($m) . '"' . ($m === $municipio ? ' selected' : '') . '>' . e($m) . '</option>';
        }
        $munOpts .= '<option value="__regionais__"' . ($municipio === '__regionais__' ? ' selected' : '') . '>▸ Consórcios / Regionais</option>';

        // entidades do município selecionado (server-side, pra funcionar pós-submit)
        $entLista = $municipio === '__regionais__' ? DomEntidades::regionais()
            : ($municipio !== '' ? DomEntidades::porMunicipio($municipio) : []);
        $entOpts = '<option value="">— escolha a entidade —</option>';
        foreach ($entLista as $e) {
            $rotulo = $municipio === '__regionais__' ? $e['nome'] : $e['tipo'] . ' — ' . $e['nome'];
            $entOpts .= '<option value="' . (int) $e['codigo'] . '"' . ((int) $e['codigo'] === $codigo ? ' selected' : '') . '>' . e($rotulo) . '</option>';
        }

        // mapa compacto p/ o JS repopular a entidade ao trocar de município
        $mapa = [];
        foreach (DomEntidades::todas() as $e) {
            $k = $e['municipio'] ?? '__regionais__';
            $mapa[$k][] = ['c' => (int) $e['codigo'], 'n' => $e['nome'], 't' => $e['tipo']];
        }
        $mapaJson = json_encode($mapa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sel = fn ($k) => $modKey === $k ? ' selected' : '';
        $seld = fn ($d) => $dias === $d ? ' selected' : '';
        $body = <<<HTML
<form class="form" method="get" id="bform">
  <select name="municipio" id="fmun">{$munOpts}</select>
  <select name="codigoEntidade" id="fent">{$entOpts}</select>
  <select name="modalidade">
    <option value="todas"{$sel('todas')}>Todas as categorias</option>
    <option value="dispensa"{$sel('dispensa')}>Dispensa</option>
    <option value="inexigibilidade"{$sel('inexigibilidade')}>Inexigibilidade</option>
    <option value="pregao"{$sel('pregao')}>Pregão</option>
    <option value="contrato"{$sel('contrato')}>Contrato</option>
  </select>
  <select name="dias">
    <option value="7"{$seld(7)}>7 dias</option>
    <option value="15"{$seld(15)}>15 dias</option>
    <option value="30"{$seld(30)}>30 dias</option>
    <option value="60"{$seld(60)}>60 dias</option>
  </select>
  <button type="submit">Buscar no DOM</button>
</form>
<div class="aviso">⚠️ Filtra pela <b>entidade exata</b> (codigoEntidade do DOM) — não por texto livre. Projetos de lei / proposições da <b>câmara</b> NÃO entram no DOM (só atos administrativos) — pro legislativo, veja o <b>Radar de Câmaras</b> (em construção).</div>
{$resultadoHtml}
<script>
const MAPA={$mapaJson};
const fmun=document.getElementById('fmun'),fent=document.getElementById('fent');
fmun.addEventListener('change',()=>{
  const lst=MAPA[fmun.value]||[];const reg=fmun.value==='__regionais__';
  fent.innerHTML='<option value="">— escolha a entidade —</option>'+
    lst.map(e=>'<option value="'+e.c+'">'+(reg?e.n:e.t+' — '+e.n).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))+'</option>').join('');
});
</script>
HTML;

        return $this->chrome('busca', 'Busca por órgão', 'Consulta ao vivo no DOM/SC — escolha município → entidade (Prefeitura/Câmara/Fundo)', $body);
    }

    // ───────────────────────── chrome / helpers ─────────────────────────

    private function fmtData(?string $d): string
    {
        if (! $d) {
            return '—';
        }
        $p = explode('-', $d);

        return count($p) === 3 ? "{$p[2]}/{$p[1]}" : $d;
    }

    private function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function chrome(string $ativo, string $titulo, string $sub, string $body)
    {
        $nav = collect([
            'civico' => ['/radar-civico', '🛰️ Cívico'],
            'todos' => ['/dom-todos', '📜 Todos'],
            'radar' => ['/dom-radar', '📡 Radar'],
            'busca' => ['/dom-busca', '🔎 Busca'],
        ])->map(fn ($it, $k) => '<a class="' . ($k === $ativo ? 'on' : '') . '" href="' . $it[0] . '">' . $it[1] . '</a>')->implode('');

        $gerado = Carbon::now()->format('d/m/Y H:i');
        $html = <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{$titulo} · DOM/SC</title>
<style>
:root{--navy:#0D2481;--red:#E63946;--green:#2D6A4F;--amber:#D4A373;--ink:#16181d;--muted:#6b7280;--line:#e6e8ee;--bg:#f5f6fa;--card:#fff}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);line-height:1.45}
header{background:var(--navy);color:#fff;padding:16px 16px 0}
header h1{margin:0;font-size:18px;font-weight:800}header .sub{font-size:12px;opacity:.82;margin:3px 0 12px}
nav{display:flex;gap:4px}nav a{flex:1;text-align:center;padding:10px 6px;color:#fff;text-decoration:none;font-size:13px;font-weight:700;opacity:.7;border-bottom:3px solid transparent}
nav a.on{opacity:1;border-bottom-color:#fff;background:rgba(255,255,255,.08)}
.disc{background:#fff7ed;border-bottom:1px solid #fed7aa;color:#9a3412;font-size:11px;padding:7px 16px}
.wrap{max-width:920px;margin:0 auto;padding:12px}
.bar{position:sticky;top:0;z-index:5;background:var(--card);border:1px solid var(--line);border-radius:11px;padding:9px;display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
.bar input,.bar select,.form input,.form select{font:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink)}
.bar input[type=search]{flex:1 1 150px;min-width:120px}
.muted{color:var(--muted);font-size:12px}.small{font-size:11.5px;margin:4px 0 10px}
.muted#cnt,.muted#count{margin-left:auto}
/* OBJ5 — firehose responsivo: table-layout fixo + larguras % => cabe SEM scroll
   lateral em qualquer tela (a soma das colunas é 100% e cada célula é overflow
   hidden). Objeto e município truncam com reticências; clique/hover abre. */
table.firehose{border-collapse:collapse;width:100%;table-layout:fixed;font-size:12px;background:#fff;border:1px solid var(--line);border-radius:11px;overflow:hidden}
table.firehose th{background:#f0f2f8;color:var(--navy);text-align:left;padding:7px 8px;font-size:10px;text-transform:uppercase;letter-spacing:.2px}
table.firehose td{padding:7px 8px;border-top:1px solid var(--line);vertical-align:top;overflow:hidden}
table.firehose th.dt,table.firehose td.dt{width:8%}
table.firehose th:nth-child(2),table.firehose td.muni{width:24%}
table.firehose th:nth-child(3),table.firehose td.modc{width:16%}
table.firehose th:nth-child(4),table.firehose td.obj{width:34%}
table.firehose th.val,table.firehose td.val{width:10%;text-align:right;font-weight:700;color:var(--green)}
table.firehose th.src,table.firehose td.src{width:8%}
table.firehose td.nw{white-space:nowrap}
table.firehose td.muni .mn{font-weight:700;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
table.firehose td.muni .reg{display:block;font-weight:500;color:var(--muted);font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
table.firehose td.obj .objt{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}
table.firehose td.obj .objt.open{white-space:normal;overflow:visible}
table.firehose td.src a{display:inline-block;margin-right:7px}
.reps{display:inline-block;margin-top:3px;font-size:10px;font-weight:700;color:var(--amber);background:#fdf6ec;border-radius:999px;padding:1px 6px}
.mod{font-size:10.5px;padding:2px 7px;border-radius:999px;background:#fdecef;color:var(--red);display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom}
a{color:var(--navy)}
main{display:flex;flex-direction:column;gap:9px}
.card{background:var(--card);border:1px solid var(--line);border-radius:13px;overflow:hidden}
.face{display:flex;gap:11px;padding:13px}
.score{flex:0 0 auto;width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff;background:var(--green)}
.score.hi{background:var(--red)}.score.mid{background:var(--navy)}.score.lo{background:var(--amber)}
.hd{flex:1;min-width:0}.muni{font-weight:800;font-size:15px}
td.muni .reg,.l1 .reg{font-weight:600;color:var(--muted);font-size:12px}
td.muni .reg{display:block;margin-top:1px}
/* card enxuto do radar: 3 linhas (nota·cidade·região / modalidade+objeto / gancho) */
.l1{line-height:1.2}
.l2{font-size:13.5px;margin:3px 0 4px;color:#222}
.l2 .modtag{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.2px;color:var(--red);background:#fdecef;padding:2px 7px;border-radius:999px;margin-right:5px;white-space:nowrap}
.l3{font-size:13.5px;font-weight:700;color:var(--navy);font-style:italic}
/* OBJ6 — barra de lentes (dual-lens) + badges */
.lens{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 10px}
.lb{font:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--ink);cursor:pointer}
.lb.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.lb b{font-weight:800;opacity:.85;margin-left:2px}
.fchk{font-size:12.5px;color:var(--muted);display:inline-flex;align-items:center;gap:5px;margin-left:auto;cursor:pointer}
.lens-dot{margin-right:5px;font-size:13px;vertical-align:baseline}
.badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}
.frec{font-size:11px;font-weight:700;color:#7a3f12;background:#fdf0e1;border:1px solid #f3d9bf;border-radius:999px;padding:2px 9px}
.frec.rep{color:var(--muted);background:#eef1f8;border-color:var(--line)}
.frec.cinca{color:#fff;background:var(--navy);border-color:var(--navy)}
.lb.cinca.on{background:var(--amber);color:#1c1408;border-color:var(--amber)}
.day-sep{font-size:12px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.5px;margin:10px 2px 0;padding-top:7px;border-top:1px dashed var(--line)}
.day-sep:first-child{border-top:none;padding-top:0;margin-top:0}
.oque{font-size:13.5px;margin:2px 0 4px}
.why{font-size:13px;color:#333;background:#f8f9fc;border-left:3px solid var(--navy);padding:7px 10px;border-radius:0 8px 8px 0;margin:2px 0 8px}
.why .lab{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;margin-bottom:2px}
/* busca: cards expansíveis com "ler completo" */
.rlist{gap:10px}
.rcard{background:var(--card);border:1px solid var(--line);border-radius:13px;padding:13px}
.rmeta{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:5px}
.rmeta .nw{font-size:12px;color:var(--muted);font-weight:600}
.robj{font-size:14.5px;font-weight:600;margin:2px 0 3px}
.rorg{font-size:12px;color:var(--muted);margin-bottom:7px}
/* busca por órgão: cabeçalho da entidade + aviso */
.enthd{background:var(--navy);color:#fff;border-radius:12px;padding:11px 14px;margin:4px 0 10px}
.enthd .entnome{font-weight:800;font-size:15.5px}
.enthd .entsub{font-size:12px;opacity:.85;margin-top:2px}
.aviso{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12px;padding:9px 12px;border-radius:10px;margin:2px 0 12px;line-height:1.45}
.form select{flex:1 1 160px;min-width:140px}
details summary{cursor:pointer;list-style:none;font-size:12.5px;font-weight:700;color:var(--navy);padding:9px 13px;border-top:1px solid var(--line);background:#f8f9fc}
summary::-webkit-details-marker{display:none}summary::before{content:"▸ "}details[open]>summary::before{content:"▾ "}
.det{padding:12px 13px}.det .org{font-size:12px;color:var(--muted);margin:4px 0}
.tg{font-size:11px;color:var(--amber);font-weight:700;text-transform:uppercase;margin-bottom:6px}
.lab{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;margin:8px 0 2px}
.meta{display:flex;gap:7px;flex-wrap:wrap;margin:7px 0}
.pill{font-size:11px;padding:3px 9px;border-radius:999px;background:#eef1f8;color:var(--navy);font-weight:600}
.pill.mod{background:#fdecef;color:var(--red)}.pill.val{background:#e8f3ee;color:var(--green);font-weight:800}
.apurar{margin:4px 0;padding-left:18px}.apurar li{font-size:13px;margin:3px 0}
.angulo{font-size:13px;font-style:italic;color:#334;margin:6px 0}
details.inner summary{border:none;background:none;padding:8px 0}.txt{font-size:12px;color:#444;white-space:pre-wrap;background:#f8f9fc;padding:9px;border-radius:8px}
.links{margin-top:9px}.src{display:inline-block;font-size:12.5px;font-weight:700;color:#fff;background:var(--navy);padding:7px 13px;border-radius:9px;text-decoration:none}
.src.pdf{background:#fff;color:var(--navy);border:1px solid var(--navy);margin-left:6px}
.form{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:6px}.form input[type=text]{flex:1 1 180px}
.form button{font:inherit;font-size:13px;font-weight:700;padding:9px 16px;border:none;border-radius:9px;background:var(--navy);color:#fff;cursor:pointer}
.empty{text-align:center;color:var(--muted);padding:36px 16px}
footer{padding:20px 16px 44px;text-align:center;color:var(--muted);font-size:11px}
</style></head><body>
<header><h1>📡 Oportunidades DOM/SC</h1><div class="sub">{$sub}</div><nav>{$nav}</nav></header>
<div class="disc">⚖️ Cada item é um <b>FATO</b> público e um <b>LEAD pra apurar</b> — não uma acusação.</div>
<div class="wrap">{$body}</div>
<footer>{$titulo} · gerado em {$gerado} · fonte: diariomunicipal.sc.gov.br (FECAM/CIGA) · Jornal Razão</footer>
</body></html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
