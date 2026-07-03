<?php

namespace App\Http\Controllers;

use App\Services\Jr\FotoOficial;
use App\Services\Jr\RascunhoCivico;
use App\Services\Jr\TellCheck;
use App\Services\Jr\ZapRascunhos;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MESA DE PAUTA — Fase 1. Triagem + fila de produção server-side do Radar Cívico.
 *
 *   POST /mesa/selecionar  ← botão ★ de cada card do radar (upsert por ato_ref)
 *   GET  /mesa             → página da fila do Lorran (cross-device, lê o banco)
 *   POST /mesa/{id}        → muda status e/ou nota (em-apuracao|feita|nova)
 *   POST /mesa/{id}/remover→ tira da fila
 *
 * Tudo gated por JrPanelKey (cookie do painel) e isento de CSRF (mesa/*). NÃO
 * publica nada, NÃO toca juiz/dispatcher/captura. ISOLADO e ADITIVO.
 */
class MesaPautaController extends Controller
{
    /** Rótulos de status (ordem = ciclo de produção). */
    private const STATUS = [
        'nova' => 'Nova',
        'em-apuracao' => 'Em apuração',
        'feita' => 'Feita',
        'rascunho-gerado' => 'Rascunho gerado',
    ];

    /** Nome curto da fonte (badge). */
    private const SRCN = [
        'dom' => 'DOM', 'camara' => 'Câmara', 'mpsc' => 'MPSC', 'tce' => 'TCE', 'tjsc' => 'TJSC',
    ];

    // ───────────────────────────── escrita ─────────────────────────────

    /** Botão ★ do radar: grava (ou atualiza o snapshot de) uma pauta na fila. */
    public function selecionar(Request $r)
    {
        $d = $r->validate([
            'ato_ref' => ['required', 'string', 'max:64', 'regex:/^(dom|camara|mpsc|tce|tjsc|prefeitura):\d+$/'],
            'source' => ['required', 'string', 'max:16'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'regiao' => ['nullable', 'string', 'max:120'],
            'objeto' => ['nullable', 'string', 'max:2000'],
            'gancho_curto' => ['nullable', 'string', 'max:500'],
            'score' => ['nullable', 'integer', 'between:0,100'],
            'url_fonte' => ['nullable', 'string', 'max:1000'],
        ]);

        $now = Carbon::now();
        $ja = DB::table('jr_pauta_fila')->where('ato_ref', $d['ato_ref'])->first();

        if ($ja) {
            // já estava na fila — só refresca o snapshot, preserva status/nota
            DB::table('jr_pauta_fila')->where('id', $ja->id)->update([
                'municipio' => $d['municipio'] ?? null,
                'regiao' => $d['regiao'] ?? null,
                'objeto' => $d['objeto'] ?? null,
                'gancho_curto' => $d['gancho_curto'] ?? null,
                'score' => $d['score'] ?? null,
                'url_fonte' => $d['url_fonte'] ?? null,
                'updated_at' => $now,
            ]);

            return response()->json(['ok' => true, 'id' => $ja->id, 'novo' => false, 'status' => $ja->status]);
        }

        $id = DB::table('jr_pauta_fila')->insertGetId([
            'ato_ref' => $d['ato_ref'],
            'source' => $d['source'],
            'municipio' => $d['municipio'] ?? null,
            'regiao' => $d['regiao'] ?? null,
            'objeto' => $d['objeto'] ?? null,
            'gancho_curto' => $d['gancho_curto'] ?? null,
            'score' => $d['score'] ?? null,
            'url_fonte' => $d['url_fonte'] ?? null,
            'status' => 'nova',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'novo' => true, 'status' => 'nova']);
    }

    /** Atualiza status e/ou nota de uma pauta. */
    public function atualizar(Request $r, int $id)
    {
        $d = $r->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::STATUS))],
            'nota' => ['nullable', 'string', 'max:4000'],
        ]);

        $patch = ['updated_at' => Carbon::now()];
        if (array_key_exists('status', $d) && $d['status'] !== null) {
            $patch['status'] = $d['status'];
        }
        if ($r->has('nota')) {
            $patch['nota'] = $d['nota'] ?? null;
        }

        $n = DB::table('jr_pauta_fila')->where('id', $id)->update($patch);

        return response()->json(['ok' => (bool) $n]);
    }

    /**
     * Fase 5 — gera um rascunho JR do ato (LLM) e ENTREGA no WHATSAPP, grupo
     * RASCUNHOS (config radar_civico.canais.rascunhos), via instância de
     * ALERTA (JRLINK_ALERT_ZAPI_*, ZapRascunhos). BLOCO 2 (02/07): o Telegram
     * voltou a ser 100% do Gerador→FB — nada do radar passa mais por ele.
     * NUNCA a instância 276 (captura) nem a 884 (disparador). Fail-closed:
     * sem instância/grupo, só gera e mostra na Mesa. Manual, NÃO publica.
     */
    public function rascunho(int $id, RascunhoCivico $gerador, ZapRascunhos $zap)
    {
        $p = DB::table('jr_pauta_fila')->where('id', $id)->first();
        if (! $p) {
            return response()->json(['ok' => false, 'erro' => 'pauta não encontrada'], 404);
        }

        $ato = $gerador->carregar($p->ato_ref);
        if (! $ato) {
            return response()->json(['ok' => false, 'erro' => 'ato de origem não encontrado (pode ter saído da base)'], 422);
        }

        $r = $gerador->gerar($p->ato_ref);
        if (empty($r)) {
            return response()->json(['ok' => false, 'erro' => 'o gerador não retornou rascunho — tente de novo'], 502);
        }

        // O5 — tell-check SEMPRE (heurística + cético), igual ao caminho AUTO.
        // NUNCA segura/descarta — só regenera 1x informando o tell e anota flag.
        $tellCheck = app(TellCheck::class);
        $tellFlag = null;
        $score = $tellCheck->avaliar($r['titulo'], $r['lead'], $r['corpo'], (string) ($ato['texto_bruto'] ?? ''));
        if ($score['grave']) {
            $motivos = array_map(fn ($t) => $t['tell'], array_filter($score['tells'], fn ($t) => $t['grave']));
            $regen = $gerador->gerar($p->ato_ref, $motivos);
            if (! empty($regen)) {
                $r = $regen;
                $score = $tellCheck->avaliar($r['titulo'], $r['lead'], $r['corpo'], (string) ($ato['texto_bruto'] ?? ''));
            }
            if ($score['grave']) {
                $graveTells = array_values(array_filter($score['tells'], fn ($t) => $t['grave']));
                $tellFlag = mb_strimwidth($graveTells[0]['tell'] ?? 'anti-ia', 0, 60, '…');
            }
        }

        // versão ANOTADA (checklist/fonte/disclaimer) fica na FILA da Mesa —
        // BLOCO 8a: o grupo recebe SÓ o formato limpo, igual ao caminho AUTO.
        $interno = $gerador->formatar($r, $ato);
        $now = Carbon::now();

        DB::table('jr_pauta_fila')->where('id', $id)->update([
            'rascunho' => $interno,
            'rascunho_at' => $now,
            'status' => 'rascunho-gerado',
            'updated_at' => $now,
        ]);

        // BLOCO 8b: foto oficial só de página de órgão (prefeitura/câmara);
        // DOM/MPSC/TCE não têm imagem — mensagem sai sem foto, avisada.
        $foto = null;
        $urlFonte = (string) ($ato['url_fonte'] ?? $p->url_fonte ?? '');
        if ($urlFonte !== '' && in_array($ato['source'] ?? '', ['prefeitura', 'camara'], true)) {
            $orgao = ($ato['source'] === 'camara' ? 'Câmara de ' : 'Prefeitura de ').(string) ($ato['municipio'] ?? $p->municipio ?? '');
            $foto = app(FotoOficial::class)->buscar($urlFonte, $orgao);
        }

        // entrega no WhatsApp, grupo RASCUNHOS (fail-closed se não configurado)
        $grupo = (string) config('radar_civico.canais.rascunhos');
        $fotoMsgId = null;
        $messageId = null;
        if ($grupo !== '' && $zap->configurado()) {
            if ($foto !== null) {
                $fotoMsgId = $zap->imagemArquivo($foto['abs'], $r['titulo'], $grupo);
            }
            $texto = $gerador->formatarLimpo($r, $foto, $fotoMsgId !== null, 'rascunho da Mesa', $tellFlag);
            $messageId = $zap->texto($texto, $grupo) ?? $fotoMsgId; // texto falhou? a foto ancora o ✅
        }

        // BLOCO 2 (03/07): registra a entrega → o ✅ (reply/reação) no grupo
        // casa o messageId aqui e cria o DRAFT no WP (ZapAprovacaoController).
        if ($messageId !== null) {
            DB::table('jr_rascunho_entregas')->updateOrInsert(
                ['ato_ref' => $p->ato_ref, 'tipo' => 'mesa'],
                [
                    'message_id' => $messageId,
                    'gate_motivo' => $tellFlag !== null ? "⚠ tell:{$tellFlag}" : null,
                    'payload' => json_encode($r + [
                        'municipio' => (string) ($p->municipio ?? ''),
                        'url_fonte' => $urlFonte,
                        'foto_path' => $foto['path'] ?? null,
                        'foto_credito' => $foto['credito'] ?? null,
                        'foto_message_id' => $fotoMsgId,
                    ], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return response()->json([
            'ok' => true,
            'rascunho' => $interno,
            'enviado' => $messageId !== null,
            'com_foto' => $fotoMsgId !== null,
            'destino_configurado' => $zap->configurado() && $grupo !== '',
            'status' => 'rascunho-gerado',
            'tell_flag' => $tellFlag,
        ]);
    }

    /** Remove uma pauta da fila. */
    public function remover(int $id)
    {
        $n = DB::table('jr_pauta_fila')->where('id', $id)->delete();

        return response()->json(['ok' => (bool) $n]);
    }

    // ───────────────────────────── leitura ─────────────────────────────

    /** Quantas pautas há na fila (badge do radar). */
    public function contagem()
    {
        return response()->json(['total' => DB::table('jr_pauta_fila')->count()]);
    }

    public function index()
    {
        $rows = DB::table('jr_pauta_fila')->orderByDesc('updated_at')->get();

        $itens = $rows->map(fn ($p) => [
            'id' => (int) $p->id,
            'ato_ref' => $p->ato_ref,
            'source' => $p->source,
            'src_nome' => self::SRCN[$p->source] ?? strtoupper($p->source),
            'municipio' => $p->municipio,
            'regiao' => $p->regiao,
            'objeto' => $p->objeto,
            'gancho_curto' => $p->gancho_curto,
            'score' => $p->score !== null ? (int) $p->score : null,
            'url_fonte' => $p->url_fonte,
            'status' => $p->status,
            'nota' => $p->nota,
            'rascunho' => $p->rascunho,
            'data' => $p->created_at ? Carbon::parse($p->created_at)->format('d/m H:i') : '',
        ])->all();

        $cont = ['total' => count($itens)];
        foreach (array_keys(self::STATUS) as $k) {
            $cont[$k] = 0;
        }
        foreach ($itens as $it) {
            $cont[$it['status']] = ($cont[$it['status']] ?? 0) + 1;
        }

        $json = json_encode($itens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statusJson = json_encode(self::STATUS, JSON_UNESCAPED_UNICODE);

        return response($this->html($json, $statusJson, $cont))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function html(string $json, string $statusJson, array $c): string
    {
        $gerado = Carbon::now()->format('d/m/Y H:i');
        $t = $c['total'];
        $nova = $c['nova'] ?? 0;
        $apur = $c['em-apuracao'] ?? 0;
        $feita = $c['feita'] ?? 0;

        return <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Mesa de Pauta · Radar Cívico</title>
<style>
:root{--navy:#0D2481;--red:#E63946;--green:#2D6A4F;--amber:#D4A373;--ink:#16181d;--muted:#6b7280;--line:#e6e8ee;--bg:#f5f6fa;--card:#fff;
  --c-dom:#0D2481;--c-camara:#7c3aed;--c-mpsc:#b45309;--c-tce:#0f766e;--c-tjsc:#9d174d}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);line-height:1.45}
header{background:var(--navy);color:#fff;padding:16px 16px 12px}
header h1{margin:0;font-size:19px;font-weight:800}header .sub{font-size:12px;opacity:.85;margin-top:3px}
header a.back{color:#fff;opacity:.9;font-size:12px;text-decoration:none;font-weight:700}
.wrap{max-width:940px;margin:0 auto;padding:12px}
.bar{position:sticky;top:0;z-index:5;background:var(--card);border:1px solid var(--line);border-radius:11px;padding:9px;display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:9px}
.bar input,.bar select{font:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink)}
.bar input[type=search]{flex:1 1 160px;min-width:130px}
.fl{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:9px}
.fb{font:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--ink);cursor:pointer}
.fb.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.fb b{opacity:.75;margin-left:3px}
.count{margin-left:auto;font-size:12px;color:var(--muted)}
main{display:flex;flex-direction:column;gap:9px}
.card{background:var(--card);border:1px solid var(--line);border-radius:13px;padding:13px;display:flex;gap:11px}
.score{flex:0 0 auto;width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#fff;background:var(--green)}
.score.hi{background:var(--red)}.score.mid{background:var(--navy)}.score.lo{background:var(--amber)}.score.na{background:var(--muted)}
.bd{flex:1;min-width:0}
.l1{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.src-badge{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;padding:2px 7px;border-radius:999px;color:#fff}
.src-dom{background:var(--c-dom)}.src-camara{background:var(--c-camara)}.src-mpsc{background:var(--c-mpsc)}.src-tce{background:var(--c-tce)}.src-tjsc{background:var(--c-tjsc)}
.muni{font-weight:800;font-size:15px}.reg{font-weight:600;color:var(--muted);font-size:12px}
.when{margin-left:auto;font-size:11px;color:var(--muted)}
.obj{font-size:13.5px;margin:4px 0;color:#222}
.hook{font-size:13.5px;font-weight:700;color:var(--navy);font-style:italic}
.st{display:inline-block;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;padding:2px 8px;border-radius:999px;margin-top:6px}
.st-nova{background:#eef1f8;color:var(--navy)}.st-em-apuracao{background:#fff7ed;color:#9a3412}
.st-feita{background:#ecfdf5;color:#065f46}.st-rascunho-gerado{background:#f3e8ff;color:#6b21a8}
.acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px;align-items:center}
.acts button,.acts a{font:inherit;font-size:12px;font-weight:700;padding:6px 11px;border-radius:8px;border:1px solid var(--line);background:#fff;color:var(--navy);cursor:pointer;text-decoration:none}
.acts button.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.acts .del{color:var(--red);border-color:#f3c6ca;margin-left:auto}
.acts .src{color:#fff;background:var(--navy);border-color:var(--navy)}
.acts .rasc{color:#6b21a8;border-color:#e3d2f5;background:#faf5ff}
.acts .rasc:disabled{opacity:.6;cursor:wait}
.rascunho-box{margin-top:8px}
.rasc-txt{white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:12.5px;line-height:1.5;background:#faf5ff;border:1px solid #e9d5ff;border-left:3px solid #7c3aed;border-radius:0 8px 8px 0;padding:10px 12px;margin:0;max-height:360px;overflow:auto}
.rasc-feed{font-size:11.5px;color:var(--muted);margin-top:4px;font-weight:700}
.nota{margin-top:8px}
.nota textarea{width:100%;font:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--line);border-radius:9px;resize:vertical;min-height:38px;background:#fcfcfe;color:var(--ink)}
.nota .save{font-size:11px;color:var(--muted);margin-top:3px;height:14px}
.empty{text-align:center;color:var(--muted);padding:42px 16px}
footer{padding:18px 16px 40px;text-align:center;color:var(--muted);font-size:11px}
</style></head><body>
<header>
  <a class="back" href="/radar-civico">‹ Radar Cívico</a>
  <h1>📌 Mesa de Pauta</h1>
  <div class="sub">A fila de produção — selecione no radar, acompanhe aqui do PC ou do celular</div>
</header>
<div class="wrap">
<div class="fl">
  <button type="button" class="fb on" data-st="">Tudo <b>{$t}</b></button>
  <button type="button" class="fb" data-st="nova">Novas <b>{$nova}</b></button>
  <button type="button" class="fb" data-st="em-apuracao">Em apuração <b>{$apur}</b></button>
  <button type="button" class="fb" data-st="feita">Feitas <b>{$feita}</b></button>
</div>
<div class="bar">
  <input type="search" id="q" placeholder="🔎 cidade, objeto, gancho, nota…">
  <select id="src"><option value="">Todas as fontes</option><option value="dom">DOM</option><option value="camara">Câmara</option><option value="mpsc">MPSC</option><option value="tce">TCE</option><option value="tjsc">TJSC</option></select>
  <span class="count" id="count"></span>
</div>
<main id="lista"></main>
</div>
<footer>Mesa de Pauta · Radar Cívico de SC · gerado em {$gerado} · uso editorial interno</footer>
<script>
let DADOS={$json};
const STN={$statusJson};
const SRCI={dom:"🧾",camara:"📜",mpsc:"⚖️",tce:"💰",tjsc:"👨‍⚖️"};
const esc=s=>(s||"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const cls=s=>s==null?"na":s>=80?"hi":s>=60?"mid":s>=40?"":"lo";
let stSel="",srcSel="";
async function post(url,body){
  const r=await fetch(url,{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify(body||{})});
  if(!r.ok)throw new Error(r.status);return r.json();
}
function card(d){
  const reg=d.regiao?'<span class="reg"> · '+esc(d.regiao)+'</span>':'';
  const sc=d.score==null?'—':d.score;
  const srcl=d.url_fonte?'<a class="src" href="'+esc(d.url_fonte)+'" target="_blank" rel="noopener">fonte</a>':'';
  let btns='';
  for(const k in STN){const on=d.status===k?' on':'';btns+='<button class="setst'+on+'" data-k="'+k+'">'+esc(STN[k])+'</button>';}
  return '<div class="card" data-id="'+d.id+'">'+
    '<div class="score '+cls(d.score)+'">'+sc+'</div>'+
    '<div class="bd">'+
      '<div class="l1"><span class="src-badge src-'+d.source+'">'+(SRCI[d.source]||"")+' '+esc(d.src_nome)+'</span>'+
        '<span class="muni">'+esc(d.municipio||"—")+'</span>'+reg+
        '<span class="when">'+esc(d.data)+'</span></div>'+
      (d.objeto?'<div class="obj">'+esc(d.objeto)+'</div>':'')+
      (d.gancho_curto?'<div class="hook">'+esc(d.gancho_curto)+'</div>':'')+
      '<div><span class="st st-'+d.status+'">'+esc(STN[d.status]||d.status)+'</span></div>'+
      '<div class="acts">'+btns+'<button class="rasc">'+(d.rascunho?'↻ refazer rascunho':'✍️ criar rascunho')+'</button>'+srcl+'<button class="del">remover</button></div>'+
      '<div class="nota"><textarea placeholder="anotação livre…">'+esc(d.nota||"")+'</textarea><div class="save"></div></div>'+
      '<div class="rascunho-box">'+(d.rascunho?('<pre class="rasc-txt">'+esc(d.rascunho)+'</pre>'):'')+'</div>'+
    '</div>'+
  '</div>';
}
function render(){
  const q=document.getElementById("q").value.toLowerCase().trim();
  let arr=DADOS.filter(d=>{
    if(stSel&&d.status!==stSel)return false;
    if(srcSel&&d.source!==srcSel)return false;
    if(q){const h=((d.municipio||"")+" "+(d.regiao||"")+" "+(d.objeto||"")+" "+(d.gancho_curto||"")+" "+(d.nota||"")).toLowerCase();if(!h.includes(q))return false;}
    return true;});
  document.getElementById("count").textContent=arr.length+" pautas";
  const m=document.getElementById("lista");
  m.innerHTML=arr.length?arr.map(card).join(""):'<div class="empty">Nenhuma pauta na fila ainda.<br>Selecione com ★ no <a href="/radar-civico">Radar Cívico</a>.</div>';
}
function obj(id){return DADOS.find(d=>d.id==id);}
document.getElementById("lista").addEventListener("click",async e=>{
  const cardEl=e.target.closest(".card");if(!cardEl)return;const id=+cardEl.dataset.id;const d=obj(id);
  if(e.target.classList.contains("rasc")){
    const btn=e.target;const old=btn.textContent;btn.disabled=true;btn.textContent="gerando… (uns 20s)";
    try{
      const j=await post("/mesa/"+id+"/rascunho",{});
      if(!j.ok){alert(j.erro||"falhou");btn.disabled=false;btn.textContent=old;return;}
      d.rascunho=j.rascunho;d.status=j.status;
      const st=cardEl.querySelector(".st");if(st){st.className="st st-"+d.status;st.textContent=STN[d.status]||d.status;}
      cardEl.querySelectorAll(".setst").forEach(x=>x.classList.toggle("on",x.dataset.k===d.status));
      const feed=j.enviado?"📲 enviado no seu WhatsApp ✓":(j.destino_configurado?"gerado — envio ao WhatsApp falhou (veja o log)":"gerado — configure RADAR_CIVICO_RASCUNHO_PHONE pra receber no WhatsApp");
      cardEl.querySelector(".rascunho-box").innerHTML='<pre class="rasc-txt">'+esc(j.rascunho)+'</pre><div class="rasc-feed">'+esc(feed)+'</div>';
      btn.disabled=false;btn.textContent="↻ refazer rascunho";
    }catch(_){alert("falhou ao gerar");btn.disabled=false;btn.textContent=old;}
    return;
  }
  if(e.target.classList.contains("setst")){
    const k=e.target.dataset.k;try{await post("/mesa/"+id,{status:k});d.status=k;render();}catch(_){alert("falhou");}
  }else if(e.target.classList.contains("del")){
    if(!confirm("Remover da fila?"))return;
    try{await post("/mesa/"+id+"/remover",{});DADOS=DADOS.filter(x=>x.id!==id);render();}catch(_){alert("falhou");}
  }
});
let notaTimer={};
document.getElementById("lista").addEventListener("input",e=>{
  if(e.target.tagName!=="TEXTAREA")return;
  const cardEl=e.target.closest(".card");const id=+cardEl.dataset.id;const d=obj(id);
  const val=e.target.value;const saveEl=cardEl.querySelector(".save");
  clearTimeout(notaTimer[id]);saveEl.textContent="…";
  notaTimer[id]=setTimeout(async()=>{
    try{await post("/mesa/"+id,{nota:val});d.nota=val;saveEl.textContent="salvo ✓";setTimeout(()=>saveEl.textContent="",1500);}
    catch(_){saveEl.textContent="erro";}
  },650);
});
document.querySelectorAll(".fb").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll(".fb").forEach(x=>x.classList.remove("on"));b.classList.add("on");stSel=b.dataset.st;render();}));
document.getElementById("src").addEventListener("change",e=>{srcSel=e.target.value;render();});
document.getElementById("q").addEventListener("input",render);
render();
</script>
</body></html>
HTML;
    }
}
