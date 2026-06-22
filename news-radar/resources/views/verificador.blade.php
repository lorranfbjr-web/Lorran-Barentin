<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificador de pauta — Radar JR</title>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--royal:#0061FF;--navy:#0D2481;--sky:#18ADFE;--mut:#5b6680;--line:#e6eaf2;--bg:#f4f6fb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:#16203a;font-family:'Open Sans',system-ui,Arial,sans-serif;font-size:15px;line-height:1.5}
.wrap{max-width:820px;margin:0 auto;padding:0 16px 80px}
header.top{background:linear-gradient(180deg,var(--navy),#102a8c);color:#fff;padding:16px;margin-bottom:18px}
header .brand{max-width:820px;margin:0 auto;display:flex;align-items:center;gap:10px}
header b{font-size:19px;font-weight:800}
header a{color:#cfe0ff;font-size:13px;margin-left:auto}
textarea,input[type=url]{width:100%;border:1px solid var(--line);border-radius:10px;padding:11px;font:inherit;font-size:14px;margin-bottom:10px}
textarea{min-height:150px;resize:vertical}
.muted{color:var(--mut);font-size:13px}
button.go{background:var(--royal);color:#fff;border:none;border-radius:10px;padding:11px 20px;font:inherit;font-weight:800;cursor:pointer}
button.go:disabled{opacity:.6;cursor:wait}
.sep{text-align:center;color:var(--mut);font-size:12px;margin:6px 0}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin-top:16px}
.verdict{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.badge{font-weight:800;border-radius:999px;padding:5px 14px;font-size:14px}
.b-yes{background:#e6f7ec;color:#1f8a4c}.b-no{background:#fdecec;color:#c0392b}
.b-warn{background:#fff4e0;color:#b06a00}
.score{font-weight:800;font-size:18px;background:var(--navy);color:#fff;border-radius:8px;padding:3px 12px}
h3{margin:14px 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.3px;color:var(--navy)}
ul{margin:4px 0;padding-left:20px}li{margin:3px 0}
.chips span{display:inline-block;background:#eef4ff;color:var(--royal);border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;margin:2px 4px 0 0}
.risk{color:#b06a00}.lac{color:#7a4f00}
.pub a{font-weight:600}.pub .d{color:var(--mut);font-size:12px}
.err{background:#fdecec;color:#c0392b;border-radius:10px;padding:10px;margin-top:12px}
.solid{background:#fff4e0;border:1px solid #ffd98a;border-radius:10px;padding:10px;margin-top:12px;color:#8a5b00;font-weight:700}
</style>
</head>
<body>
<header class="top"><div class="brand"><b>Verificador de pauta</b><a href="/radar{{ $key ? '?key='.urlencode($key) : '' }}">← voltar pro Radar</a></div></header>
<div class="wrap">
  <p class="muted">Cole o TEXTO de uma pauta ou um LINK. O verificador cruza com a régua do juiz, o DNA do @jornalrazao e o banco de raspagem — diz se vale, por quê, o que falta e quem mais publicou. Não publica nada.</p>
  <textarea id="texto" placeholder="Cole aqui o texto da pauta…"></textarea>
  <div class="sep">— ou —</div>
  <input type="url" id="url" placeholder="https://… (cole um link; o corpo é extraído automaticamente)">
  <button class="go" id="go">Verificar pauta</button>
  <div id="out"></div>
</div>
<script>
(function(){
  var KEY=@json($key);
  function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
  function li(arr){return '<ul>'+(arr||[]).map(function(x){return '<li>'+esc(x)+'</li>';}).join('')+'</ul>';}
  document.getElementById('go').addEventListener('click',function(){
    var btn=this, out=document.getElementById('out');
    var texto=document.getElementById('texto').value.trim();
    var url=document.getElementById('url').value.trim();
    if(!texto && !url){out.innerHTML='<div class="err">Cole um texto ou um link.</div>';return;}
    btn.disabled=true;btn.textContent='Verificando… (~30s)';out.innerHTML='';
    fetch('/radar/verificar?key='+encodeURIComponent(KEY),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({texto:texto,url:url})})
      .then(function(r){return r.json();})
      .then(function(d){
        btn.disabled=false;btn.textContent='Verificar pauta';
        if(d.error){out.innerHTML='<div class="err">'+esc(d.error)+'</div>';return;}
        var v=d.veredito||{}, a=d.analise||{};
        var ehPauta=v.eh_pauta && (a.parece_jr!==false);
        var html='<div class="card">';
        html+='<div class="verdict"><span class="badge '+(ehPauta?'b-yes':'b-no')+'">'+(ehPauta?'✓ Vale como pauta':'✕ Fraca como pauta')+'</span>';
        if(typeof v.score==='number') html+='<span class="score">'+v.score+'</span>';
        if(a.forca!=null) html+='<span class="muted">força IG: '+esc(a.forca)+'/100</span>';
        html+='</div>';
        if(d.solidariedade) html+='<div class="solid">🚫 Trava de solidariedade/vaquinha/Pix: '+esc(d.gate_motivo)+' → vai pra FILA HUMANA, nunca automático.</div>';
        html+='<h3>Por quê</h3><p>'+esc(a.por_que||v.motivo||'—')+'</p>';
        html+='<div class="chips">';
        if(v.escopo) html+='<span>escopo: '+esc(v.escopo)+'</span>';
        if(v.tipo_gancho) html+='<span>gancho: '+esc(v.tipo_gancho)+'</span>';
        if(v.cidade) html+='<span>cidade: '+esc(v.cidade)+'</span>';
        html+='</div>';
        if((a.lacunas||[]).length){html+='<h3 class="lac">Lacunas — confirmar antes de publicar</h3>'+li(a.lacunas);}
        if((a.riscos||[]).length){html+='<h3 class="risk">Riscos editoriais (SEO/plágio/atribuição)</h3>'+li(a.riscos);}
        if(a.recomendacao){html+='<h3>Recomendação</h3><p>'+esc(a.recomendacao)+'</p>';}
        html+='<h3>Quem mais publicou ('+((d.quem_publicou||[]).length)+')</h3>';
        if((d.quem_publicou||[]).length){
          html+='<div class="pub">'+d.quem_publicou.map(function(p){return '<div>• <a href="'+esc(p.url)+'" target="_blank" rel="noopener">'+esc(p.host)+'</a> <span class="d">'+esc((p.data||'').substring(0,10))+'</span><br><span class="muted">'+esc(p.titulo)+'</span></div>';}).join('')+'</div>';
        } else { html+='<p class="muted">Ninguém parecido no banco (últimos 30 dias) — pode ser furo ou tema fora do radar.</p>'; }
        html+='</div>';
        out.innerHTML=html;
      }).catch(function(){btn.disabled=false;btn.textContent='Verificar pauta';out.innerHTML='<div class="err">Falha na verificação.</div>';});
  });
})();
</script>
</body>
</html>
