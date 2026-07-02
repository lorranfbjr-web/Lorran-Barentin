@php
    // Título "shouty" (ALL CAPS, comum no IG) vira sentence-case; título normal preservado.
    $titulo = function (string $t): string {
        $letras = preg_replace('/[^\p{L}]/u', '', $t);
        $up = preg_replace('/[^\p{Lu}]/u', '', $t);
        if ($letras !== '' && mb_strlen($up) / max(mb_strlen($letras), 1) > 0.6) {
            $t = mb_strtoupper(mb_substr($t, 0, 1)) . mb_strtolower(mb_substr($t, 1));
        }
        return $t;
    };
    $idadeTxt = function ($h): string {
        if ($h === null) return '';
        return $h < 1 ? 'agora' : ($h < 48 ? round($h) . 'h' : round($h / 24) . 'd');
    };
    $temKey = ($key ?? '') !== '';
    $renderCard = function (array $a) use ($titulo, $idadeTxt, $temKey) {
        $velho = $a['idade_horas'] !== null && $a['idade_horas'] >= 24;
        $scoreCls = $a['score_atual'] >= 80 ? 's-hot' : ($a['score_atual'] >= 60 ? 's-warm' : 's-mid');
        $cidadeChip = $a['cidade'] ? '<span class="chip cidade">' . e($a['cidade']) . '</span>' : '';
        $midiaChip = !empty($a['sem_midia']) ? '<span class="chip nomedia" title="Gancho viral/curiosidade sem vídeo ou câmera no título — confirmar mídia antes de priorizar">⚠ sem mídia</span>' : '';
        $motivo = $a['motivo'] ? '<p class="motivo">' . e($a['motivo']) . '</p>' : '';
        $portais = '';
        if ($a['n_portais'] >= 2) {
            $lis = '';
            foreach ($a['portais'] as $p) {
                $lis .= '<li><a href="' . e($p['url']) . '" target="_blank" rel="noopener">' . e($p['nome']) . ' ↗</a></li>';
            }
            $portais = '<details class="portais"><summary>🔥 ' . $a['n_portais'] . ' portais cobrindo</summary><ul>' . $lis . '</ul></details>';
        }

        $btnPauta = $temKey
            ? '<button type="button" class="btn-pauta" data-assunto="' . e($a['assunto_id']) . '">📝 Montar pauta</button>'
            : '';

        return '<article class="card"'
            . ' data-assunto="' . e($a['assunto_id']) . '"'
            . ' data-origens="' . e(implode(' ', $a['origens'])) . '"'
            . ' data-cidade="' . e($a['cidade'] ?? '') . '"'
            . ' data-editoria="' . e($a['editoria']) . '"'
            . ' data-idade="' . e((string) ($a['idade_horas'] ?? 999)) . '"'
            . ' data-busca="' . e(mb_strtolower($a['titulo'] . ' ' . ($a['label'] ?? '') . ' ' . ($a['cidade'] ?? ''))) . '">'
            . '<div class="card-top">'
            . '<span class="score ' . $scoreCls . '">' . $a['score_atual'] . '</span>'
            . '<span class="chip ed" style="--c:' . e($a['editoria_cor']) . '">' . e($a['editoria']) . '</span>'
            . $cidadeChip
            . $midiaChip
            . '<span class="chip origem o-' . e($a['origem']) . '">' . e($a['origem_label']) . '</span>'
            . '<span class="idade ' . ($velho ? 'velho' : '') . '">' . e($idadeTxt($a['idade_horas'])) . '</span>'
            . '</div>'
            . '<h3 class="titulo"><a href="' . e($a['url']) . '" target="_blank" rel="noopener">' . e($titulo($a['n_frentes'] > 1 ? $a['label'] : $a['titulo'])) . '</a></h3>'
            . $motivo
            . '<div class="card-bot"><span class="host">' . e($a['host']) . '</span>' . $portais . $btnPauta . '</div>'
            . '</article>';
    };
@endphp
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Radar JR — vitrine</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --royal:#0061FF; --navy:#0D2481; --sky:#18ADFE;
  --ink:#0D2481; --mut:#5b6680; --line:#e6eaf2; --bg:#f4f6fb; --card:#fff;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:#16203a;font-family:'Open Sans',system-ui,Arial,sans-serif;font-size:15px;line-height:1.5}
a{color:var(--royal);text-decoration:none}
.wrap{max-width:1120px;margin:0 auto;padding:0 16px 80px}
header.top{position:sticky;top:0;z-index:20;background:linear-gradient(180deg,var(--navy),#102a8c);color:#fff;margin:0 -16px 0;padding:16px 16px 14px;box-shadow:0 2px 14px rgba(13,36,129,.18)}
.brand{display:flex;align-items:center;gap:10px;max-width:1120px;margin:0 auto}
.brand b{font-size:20px;font-weight:800;letter-spacing:.2px}
.brand .live{font-size:11px;font-weight:700;background:var(--sky);color:#012;border-radius:999px;padding:3px 9px}
.brand .upd{margin-left:auto;font-size:12px;opacity:.85}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;max-width:1120px;margin:12px auto 0}
.stat{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:8px 10px}
.stat .n{font-size:20px;font-weight:800;line-height:1}
.stat .l{font-size:11px;opacity:.85;margin-top:3px}
.controls{position:sticky;top:0;z-index:15;background:var(--bg);padding:12px 0 8px;margin-top:14px}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.tab{border:1px solid var(--line);background:#fff;color:var(--mut);border-radius:999px;padding:7px 14px;font-weight:700;font-size:13px;cursor:pointer}
.tab.on{background:var(--royal);color:#fff;border-color:var(--royal)}
.row{display:flex;gap:8px;flex-wrap:wrap}
.row input,.row select{border:1px solid var(--line);background:#fff;border-radius:10px;padding:9px 11px;font:inherit;font-size:14px;color:#16203a}
.row input[type=search]{flex:1;min-width:160px}
.toggle{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--mut);border:1px solid var(--line);background:#fff;border-radius:10px;padding:0 11px;cursor:pointer}
.sec{margin:20px 0 8px;display:flex;align-items:center;gap:8px}
.sec h2{font-size:15px;font-weight:800;color:var(--navy);margin:0}
.sec .dot{width:9px;height:9px;border-radius:50%;background:var(--sky);box-shadow:0 0 0 4px rgba(24,173,254,.18)}
.sec .ct{font-size:12px;color:var(--mut);font-weight:600}
.grid{display:grid;grid-template-columns:1fr;gap:10px}
@media(min-width:680px){.grid{grid-template-columns:1fr 1fr}.stats{gap:10px}}
@media(min-width:1000px){.grid{grid-template-columns:1fr 1fr 1fr}}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:13px 14px;box-shadow:0 1px 3px rgba(13,36,129,.05);display:flex;flex-direction:column;gap:7px}
.card-top{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.score{font-weight:800;font-size:13px;color:#fff;border-radius:8px;padding:2px 8px;min-width:30px;text-align:center}
.s-hot{background:#E63946}.s-warm{background:var(--royal)}.s-mid{background:#8a93a8}
.chip{font-size:11px;font-weight:700;border-radius:999px;padding:3px 9px;white-space:nowrap}
.chip.ed{color:#fff;background:var(--c)}
.chip.cidade{background:#eaf0ff;color:var(--navy)}
.chip.origem{background:#f0f2f7;color:var(--mut)}
.chip.o-instagram{background:#fdeaf6;color:#b5258a}
.chip.o-whatsapp{background:#e6f7ec;color:#1f8a4c}
.chip.nomedia{background:#fff4e0;color:#b06a00;font-weight:700}
.idade{margin-left:auto;font-size:12px;font-weight:700;color:var(--sky)}
.idade.velho{color:#aab2c4}
.titulo{margin:0;font-size:15.5px;font-weight:700;line-height:1.32}
.titulo a{color:#16203a}
.titulo a:hover{color:var(--royal)}
.motivo{margin:0;font-size:12.5px;color:var(--mut);line-height:1.4}
.card-bot{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:2px}
.host{font-size:11.5px;color:#9aa3b5;font-weight:600}
.portais summary{cursor:pointer;font-size:12px;font-weight:700;color:var(--royal);list-style:none}
.portais summary::-webkit-details-marker{display:none}
.portais ul{margin:7px 0 0;padding-left:16px}
.portais li{font-size:12.5px;margin:3px 0}
.btn-pauta{margin-left:auto;border:1px solid var(--royal);background:#eef4ff;color:var(--royal);border-radius:8px;padding:4px 10px;font:inherit;font-size:12px;font-weight:700;cursor:pointer}
.btn-pauta:hover{background:var(--royal);color:#fff}
.pauta-box{margin-top:4px;border-top:1px dashed var(--line);padding-top:10px;font-size:13px}
.pauta-box .pb-load{color:var(--mut);font-size:12.5px}
.pauta-box h4{margin:10px 0 4px;font-size:12px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.3px}
.pauta-box .src{font-size:12px;margin:3px 0}
.pauta-box .src a{font-weight:600}
.pauta-box details.psrc{margin:4px 0;border:1px solid var(--line);border-radius:8px;padding:6px 8px;background:#fafbff}
.pauta-box details.psrc summary{cursor:pointer;font-weight:700;font-size:12px;color:var(--navy);list-style:none}
.pauta-box details.psrc summary::-webkit-details-marker{display:none}
.pauta-box .ptxt{white-space:pre-wrap;font-size:12.5px;color:#2a3550;margin-top:6px;max-height:240px;overflow:auto}
.pauta-box .note{font-size:11.5px;color:#b06a00;background:#fff4e0;border-radius:7px;padding:5px 8px;margin:6px 0}
.pauta-box .btn-rw{border:none;background:var(--navy);color:#fff;border-radius:8px;padding:7px 12px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;margin-top:6px}
.pauta-box .btn-rw:disabled{opacity:.6;cursor:wait}
.pauta-box .res{margin-top:10px;background:#f7f9ff;border:1px solid var(--line);border-radius:10px;padding:10px}
.pauta-box .res .copy{float:right;border:1px solid var(--line);background:#fff;border-radius:6px;font-size:11px;font-weight:700;padding:2px 7px;cursor:pointer;color:var(--royal)}
.pauta-box .res ul{margin:4px 0;padding-left:18px}
.pauta-box .res li{margin:2px 0;font-size:12.5px}
.pauta-box .res .mat{white-space:pre-wrap;font-size:13px;line-height:1.55;color:#16203a}
.pauta-box .res .tag{display:inline-block;background:#eef4ff;color:var(--royal);border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;margin:2px 3px 0 0}
.pauta-box .res .lac{color:#b06a00}
.empty{text-align:center;color:var(--mut);padding:40px 0;font-weight:600}
.foot{margin-top:30px;text-align:center;font-size:11.5px;color:#9aa3b5}
</style>
</head>
<body>
<header class="top">
  <div class="brand"><b>Radar JR</b><span class="live">AO VIVO</span><span class="upd">atualizado {{ $stats['atualizado'] }}</span></div>
  <div class="stats">
    <div class="stat"><div class="n">{{ number_format($stats['processados'],0,',','.') }}</div><div class="l">processados ({{ $stats['janela_h'] }}h)</div></div>
    <div class="stat"><div class="n">{{ $stats['julgados'] }}</div><div class="l">julgados pelo Opus</div></div>
    <div class="stat"><div class="n">{{ $stats['quentes'] }}</div><div class="l">assuntos quentes</div></div>
    <div class="stat"><div class="n">{{ $stats['instagram'] }}</div><div class="l">do Instagram</div></div>
  </div>
</header>

<div class="wrap">
  <div class="controls">
    <div class="tabs" id="tabs">
      <button class="tab on" data-origem="">Tudo</button>
      <button class="tab" data-origem="feed">Portais</button>
      <button class="tab" data-origem="whatsapp">WhatsApp</button>
      <button class="tab" data-origem="instagram">Instagram</button>
      @if(($key ?? '') !== '')<a class="tab" style="margin-left:auto;text-decoration:none" href="/radar/verificar?key={{ urlencode($key) }}">🔍 Verificar pauta</a>@endif
    </div>
    <div class="row">
      <input type="search" id="busca" placeholder="Buscar no título, cidade…" autocomplete="off">
      <select id="cidade"><option value="">Cidade: todas</option>@foreach($cidades as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
      <select id="editoria"><option value="">Editoria: todas</option>@foreach($editorias as $e)<option value="{{ $e }}">{{ $e }}</option>@endforeach</select>
      <label class="toggle"><input type="checkbox" id="hoje"> só de hoje</label>
    </div>
  </div>

  @if(($acelerando ?? collect())->isNotEmpty())
  <section data-block="acelerando" style="margin-bottom:14px">
    <div class="sec"><span class="dot" style="background:#ff5722"></span><h2>🔥 Acelerando agora</h2><span class="ct">{{ $acelerando->count() }}</span></div>
    <div style="display:flex;flex-direction:column;gap:8px">
      @foreach($acelerando as $v)
      <a href="{{ $v['url'] }}" target="_blank" rel="noopener" style="display:block;background:linear-gradient(90deg,#fff3ee,#fff);border:1px solid #ffd0bd;border-left:4px solid #ff5722;border-radius:10px;padding:10px 14px;text-decoration:none;color:inherit">
        <div style="font-weight:700;font-size:15px;line-height:1.35">{{ $v['label'] }}</div>
        <div style="font-size:12.5px;color:#8a5b4a;margin-top:3px">
          {{ $v['acel_n3h'] }} itens nas últimas 3h · {{ $v['acel_portais'] }} portais · {{ $v['acel_n24h'] }} em 24h
          @if($v['cidade']) · {{ $v['cidade'] }}@endif
        </div>
      </a>
      @endforeach
    </div>
  </section>
  @endif

  <section data-block="agora">
    <div class="sec"><span class="dot"></span><h2>Agora</h2><span class="ct" data-count></span></div>
    <div class="grid">
      @forelse($agora as $a){!! $renderCard($a) !!}@empty<p class="empty">Sem pauta quente nas últimas horas.</p>@endforelse
    </div>
  </section>

  <section data-block="recentes">
    <div class="sec"><span class="dot" style="background:#c7cede;box-shadow:none"></span><h2>Últimas 24h</h2><span class="ct" data-count></span></div>
    <div class="grid">
      @forelse($recentes as $a){!! $renderCard($a) !!}@empty<p class="empty">Nada por aqui.</p>@endforelse
    </div>
  </section>

  <p class="empty" id="vazio" style="display:none">Nenhuma pauta com esses filtros.</p>
  <div class="foot">Radar JR · agrupado por assunto pelo Opus no ciclo · página lê o banco na hora, sem IA no clique.</div>
</div>

<script>
(function(){
  var origem='', q='', cidade='', editoria='', hoje=false;
  var cards=[].slice.call(document.querySelectorAll('.card'));
  function apply(){
    cards.forEach(function(c){
      var ok=true;
      if(origem && c.dataset.origens.split(' ').indexOf(origem)<0) ok=false;
      if(ok && cidade && c.dataset.cidade!==cidade) ok=false;
      if(ok && editoria && c.dataset.editoria!==editoria) ok=false;
      if(ok && hoje && parseFloat(c.dataset.idade)>=24) ok=false;
      if(ok && q && c.dataset.busca.indexOf(q)<0) ok=false;
      c.style.display = ok ? '' : 'none';
    });
    document.querySelectorAll('section[data-block]').forEach(function(s){
      var vis=s.querySelectorAll('.card:not([style*="display: none"])').length;
      var ct=s.querySelector('[data-count]'); if(ct) ct.textContent=vis+(vis===1?' assunto':' assuntos');
      s.style.display = vis ? '' : (s.querySelector('.empty')? '' : 'none');
    });
    var total=cards.filter(function(c){return c.style.display!=='none'}).length;
    var vazio=document.getElementById('vazio');
    vazio.style.display = total ? 'none' : '';
    if(!total){
      var labels={feed:'Portais',whatsapp:'WhatsApp',instagram:'Instagram'};
      vazio.textContent = origem
        ? ('Sem pauta de '+(labels[origem]||origem)+' na janela atual.')
        : 'Nenhuma pauta com esses filtros.';
    }
  }
  document.getElementById('tabs').addEventListener('click',function(e){
    var b=e.target.closest('.tab'); if(!b)return;
    document.querySelectorAll('.tab').forEach(function(t){t.classList.remove('on')});
    b.classList.add('on'); origem=b.dataset.origem; apply();
  });
  document.getElementById('busca').addEventListener('input',function(e){q=e.target.value.toLowerCase().trim();apply()});
  document.getElementById('cidade').addEventListener('change',function(e){cidade=e.target.value;apply()});
  document.getElementById('editoria').addEventListener('change',function(e){editoria=e.target.value;apply()});
  document.getElementById('hoje').addEventListener('change',function(e){hoje=e.target.checked;apply()});
  apply();
})();
</script>

@if(($key ?? '') !== '')
<script>
// Goal 3 — Montar pauta: container (texto dos portais + links) + reescrita
// unificada. Tudo atrás da chave; a página não chama LLM, só os endpoints.
(function(){
  var KEY = @json($key);
  function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
  function copyBtn(text){
    var b=document.createElement('button');b.className='copy';b.textContent='copiar';
    b.addEventListener('click',function(){navigator.clipboard.writeText(text).then(function(){b.textContent='copiado ✓';setTimeout(function(){b.textContent='copiar';},1500);});});
    return b;
  }
  function renderResultado(box, r){
    var div=document.createElement('div');div.className='res';
    var full=(r.titulo_principal?('TÍTULO: '+r.titulo_principal+'\n'):'')
      +(r.linha_fina?('LINHA FINA: '+r.linha_fina+'\n\n'):'')+(r.materia||'')
      +'\n\nTAGS: '+(r.tags||[]).join(', ');
    var html='<h4>Pauta reescrita (padrão JR)';
    if(r.editoria||r.cidade) html+=' <span style="font-weight:600;color:#5b6680;text-transform:none">· '+esc([r.editoria,r.cidade].filter(Boolean).join(' · '))+'</span>';
    html+='</h4>';
    html+='<div><b>Títulos sugeridos:</b><ul>'+(r.titulos||[]).map(function(t){return '<li>'+esc(t)+'</li>';}).join('')+'</ul></div>';
    if(r.linha_fina) html+='<div style="margin:6px 0"><b>Linha fina:</b> '+esc(r.linha_fina)+'</div>';
    html+='<div style="margin:6px 0"><b>Matéria:</b><div class="mat">'+esc(r.materia)+'</div></div>';
    html+='<div style="margin:6px 0">'+(r.tags||[]).map(function(t){return '<span class="tag">'+esc(t)+'</span>';}).join('')+'</div>';
    if((r.lacunas||[]).length) html+='<div class="lac" style="margin-top:6px"><b>⚠ Lacunas (confirmar antes de publicar):</b><ul>'+r.lacunas.map(function(l){return '<li>'+esc(l)+'</li>';}).join('')+'</ul></div>';
    if(r.modelo) html+='<div style="font-size:11px;color:#9aa3b5;margin-top:6px">gerado por '+esc(r.modelo)+(r.gerado_em?(' · '+esc(r.gerado_em)):'')+'</div>';
    div.innerHTML=html;
    div.insertBefore(copyBtn(full), div.firstChild);
    box.appendChild(div);
  }
  function renderBox(box, d){
    box.innerHTML='';
    var srcWrap=document.createElement('div');
    srcWrap.innerHTML='<h4>Portais que cobriram ('+d.n_portais+')</h4>';
    (d.portais||[]).forEach(function(p){
      var det=document.createElement('details');det.className='psrc';
      det.innerHTML='<summary>'+esc(p.host)+(p.tem_texto?'':' (sem texto extraído)')+' — <a href="'+esc(p.url)+'" target="_blank" rel="noopener">abrir ↗</a></summary>'
        +(p.tem_texto?'<div class="ptxt">'+esc(p.texto)+'</div>':'');
      srcWrap.appendChild(det);
    });
    box.appendChild(srcWrap);

    var linksTxt=(d.links_fontes||[]).join('\n');
    var lk=document.createElement('div');lk.className='src';
    lk.innerHTML='<b>Links das matérias</b> (abra pra pegar as fotos na fonte) ';
    lk.appendChild(copyBtn(linksTxt));
    box.appendChild(lk);
    if(d.sem_imagem_no_extrato){
      var note=document.createElement('div');note.className='note';
      note.textContent='O extrato não guarda imagem — as fotos se pegam abrindo cada portal acima.';
      box.appendChild(note);
    }

    var btn=document.createElement('button');btn.className='btn-rw';
    btn.textContent=d.reescrita?'✍️ Reescrever e mandar pro grupo (Opus)':'✍️ Reescrever unificando + mandar pro grupo JR Rascunhos';
    btn.addEventListener('click',function(){
      btn.disabled=true;btn.textContent='📤 enviando…';
      var old=box.querySelector('.sent');if(old)old.remove();
      fetch('/radar/assunto/'+box.dataset.assunto+'/reescrever?key='+encodeURIComponent(KEY),{method:'POST',headers:{'Accept':'application/json'}})
        .then(function(r){return r.json();})
        .then(function(rr){
          btn.disabled=false;btn.textContent='✍️ Reescrever e mandar de novo';
          var w=document.createElement('div');w.className='note sent';
          if(rr.error){w.textContent='Erro: '+rr.error;}
          else{w.textContent=rr.mensagem||'📤 Mandando pro grupo JR Rascunhos…';w.style.color='#1f8a4c';w.style.background='#e6f7ec';w.style.borderColor='#bfe6cd';}
          box.appendChild(w);
        }).catch(function(){btn.disabled=false;btn.textContent='✍️ Reescrever (tentar de novo)';});
    });
    box.appendChild(btn);
    if(d.reescrita) renderResultado(box, d.reescrita);
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest('.btn-pauta');if(!b)return;
    var card=b.closest('.card');var assunto=b.dataset.assunto;
    var box=card.querySelector('.pauta-box');
    if(box){box.style.display=box.style.display==='none'?'':'none';return;}
    box=document.createElement('div');box.className='pauta-box';box.dataset.assunto=assunto;
    box.innerHTML='<div class="pb-load">Carregando portais…</div>';
    card.appendChild(box);
    fetch('/radar/assunto/'+assunto+'?key='+encodeURIComponent(KEY),{headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();})
      .then(function(d){if(d.error){box.innerHTML='<div class="note">Erro: '+esc(d.error)+'</div>';return;}renderBox(box,d);})
      .catch(function(){box.innerHTML='<div class="note">Falha ao carregar.</div>';});
  });
})();
</script>
@endif
</body>
</html>
