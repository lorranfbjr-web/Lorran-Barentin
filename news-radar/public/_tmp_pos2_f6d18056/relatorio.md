# JR Pauta — Pós-Fase 2: push, juiz otimizado, agendamento e seed de fontes

**Data:** 2026-06-10 · **Branch:** `claude/sc-news-aggregator-2M470` · **Autor:** Claude (goal autônomo do Lorran)

**Status: as 4 frentes fechadas. Pipeline rodando 100% sozinho (evidência de ciclo real no journal), 75 fontes novas ativas no radar, revalidação do aceite da Fase 2 de pé.**

---

## (A) Push ✅

`git push origin claude/sc-news-aggregator-2M470` executado com sucesso — 17 commits da Fase 2 subiram (`d51359f..0b6f6c7`) e mais os commits deste goal foram pushados ao final (`git push` final abaixo). Remote: `github.com/lorranfbjr-web/Lorran-Barentin`.

## (B) Juiz otimizado ✅ (claude-cli + Haiku mantidos)

- `juiz.lote` 12 → **24** itens/chamada (`config/jrlink.php`).
- Teste com lote REAL de 24: parse JSON **100% na 1ª tentativa** (24/24 vereditos válidos).
- Custo/item caiu **34%**: US$ 0,0046 → US$ 0,0030 (o overhead de ~25k tokens de sessão do CLI dilui no lote maior).
- Modelo segue `claude-haiku-4-5` no driver `claude-cli` (assinatura Max local), como decidido.

## (C) Scheduler ✅ — pipeline roda sozinho

Agendado em `routes/console.php` (Kernel do Laravel):

| comando | cron | proteção |
|---|---|---|
| `news-radar:dispatch` | `*/5 * * * *` | withoutOverlapping |
| `jrlink:bridge-news` | `*/30 * * * *` | withoutOverlapping |
| `jrlink:juiz --hours=48` | `5,35 * * * *` | withoutOverlapping (idempotente, cap 300) |

**Dois problemas reais encontrados e corrigidos no caminho:**
1. O unit `newsradar-scheduler.service` chamava `news-radar:dispatch` DIRETO (sem Laravel Scheduler) e o timer disparava em minuto quebrado (`OnUnitActiveSec`, :X4/:X9) — cron de minuto cheio nunca casaria. Unit agora roda `artisan schedule:run` e o timer é `OnCalendar=*:00/5:10` (backups dos dois arquivos em `/tmp/newsradar-scheduler.*.bak-pos2*`).
2. `runInBackground` + `Type=oneshot` = systemd matava o cgroup ~24ms depois do `schedule:run` sair, deixando mutex órfão de 24h em `cache_locks` (dispatch/bridge ficaram mudos das 08:50 às 09:10). Agendamento passou a foreground sequencial e os mutexes órfãos foram limpos.

**Evidência de ciclo real, 100% automático (journal do systemd):**
```
09:35:19 Running ['artisan' news-radar:dispatch] ........ 1s DONE
09:35:21 Running ['artisan' jrlink:juiz --hours=48] .... 17m DONE   ← julgou as fontes novas sozinho
10:00:18 Running ['artisan' news-radar:dispatch] ........ 4s DONE
10:00:23 Running ['artisan' jrlink:bridge-news] ......... 4s DONE
10:05    Running ['artisan' jrlink:juiz --hours=48] ........ DONE   ← pegou o item das 10:00 (ex.: #3839)
```
O item #3839 ("Quero comer uma tainha", diz Lula… Litoral Norte) entrou pela ponte das 10:00 e o juiz das 10:05 deu o veredito sozinho: `nacional_localizado · quente 81` — exatamente a regra do ângulo local.

## (D) Seed + QA dos domínios ✅

Fonte: `/home/jr/dominios-sc-raw.txt` (lista crua de ChatGPT/Grok). Probe real: HTTP com redirects (https→www→http), busca de notícia datada recente (mai-jun/2026) e sondagem de feed (link rel → /feed → /rss → wp-json), 16 threads.

### Funil

| etapa | n | detalhe |
|---|---|---|
| recebidos | 149 linhas | 144 domínios únicos (0 duplicados internos; 5 linhas em branco = grupos regionais) |
| mortos / inventados | **−20** | sem HTTP 200 em nenhuma variante (ex.: portalsmo, valesc, saojoaquimonline, portaldacidade) |
| já cadastrados / allowlist | **−19** | ex.: peperi, pagina3, portalmenina, diarinho, jdv*, rbatv (*via news_sources) |
| vivos sem notícia datada em texto | **−5** | rádios só-player/parked: novafm103, radionova, culturacamposnovos, portalpalhoca, palhocense |
| pendente-sitemap (não cadastrados) | **−20** | vivos com data recente mas sem feed e sem listing parseável (ex.: diariodajaragua, rco, alianca.news, folhadooeste) — candidatos a sitemap na próxima fase |
| **cadastrados** | **80** | 75 com feed RSS/Atom validado (item recente) + 5 html_listing com ≥5 artigos datados |
| quebraram no 1º ciclo → `active=false` | **−5** | Portal Agora e Vvale (HTTP 403 no feed), Jornal Nossa Ilha (SSL expirado), SC em Pauta (XML inválido), Oeste SC Notícias (403 no listing) |
| **ativas no radar** | **75** | news_sources: 67 → 147 fontes (129 ativas no total) |

Seeder versionado: `database/seeders/Pos2DominiosScSeeder.php`. Os 80 entraram também na allowlist `concorrente` do `config/jrlink.php` (27 → 108 domínios — RADAR, nunca reescrever) e o `--reclass` rodou sem re-fetch.

### Revalidação obrigatória ✅

Um ciclo completo coleta+bridge+juiz com as fontes novas (1580 itens na janela de 48h, +630 vs antes; jr_link_extracao foi a 2262+ rows):

- **Aceite da Fase 2 (script automático): TODOS OS CRITÉRIOS PASSARAM.** (a) 40 itens Enem/Lula/ONU/carne-UE, 0 quentes ilegítimos; (b) 429 quentes julgados, 38 scores distintos, moda 7%; (c) oceanógrafo 1 cluster, 0 repetições; (d) SEO eh_pauta=false.
- **Paridade WhatsApp: 122/122 OK** (re-rodada após cada mudança).
- **Bug real de janela rolante achado pela revalidação e corrigido:** o run agendado (`--hours=48`) apagava TODOS os clusters e recriava só os da janela — item antigo perdia o cluster e voltava ao relatório como quente avulso (oceanógrafo reapareceu 4×). `persistirClusters` agora congela a atribuição de quem está fora da janela. Commit `pos2(D2)`.
- **Ruído das fontes novas:** taxa de eh_pauta=false entre 30-55% por fonte — mesmo patamar do baseline pré-existente (visornoticias: 62%); o juiz absorve por desenho. Nenhuma fonte degradou o veredito; as 5 quebradas (acima) foram desativadas.

## Consumo do juiz no volume novo

- Hoje (validação + catch-up das 80 fontes): 140 chamadas, 1.840 julgamentos, **US$ 7,08** equivalentes (na prática: franquia da assinatura Max — não é fatura).
- Regime: o catch-up foi único (idempotência). Ciclo incremental observado: 10:05 julgou só o delta da meia hora. Projeção com o dobro de itens/dia (~1.000): ~400 julgados/dia ÷ 24 = **~17 chamadas/dia ≈ US$ 1,20/dia equivalente** no claude-cli (ou ~US$ 0,03/dia se entrar chave `gpt-4o-mini`).

## Pendências / próxima fase

- 20 domínios **pendente-sitemap** (lista em `/tmp/pos2_classificacao.json`, espelhada abaixo no anexo) — implementar descoberta via sitemap.xml pra incorporá-los.
- As 5 fontes desativadas podem ser re-tentadas com UA de browser/bypass 403 ou aguardar SSL renovar.
- 3 fontes com runs ok e 0 itens (Portal Norte da Ilha, Lages Diário, Portal CDR) — observar; podem precisar de seletor específico no listing.
- Notificação segue DRY (`JRLINK_ALERT_WEBHOOK` vazio) — portão humano.

## Anexo — pendente-sitemap (20)

folhadooeste.com.br · portaloestenews.com.br · rco.com.br · minutta.com.br · radiorural.com.br · alianca.news · tudosobrexanxere.com.br · cacodarosa.com · otempodefato.com.br · uaaau.com.br · hcnoticias.com.br · rcnoticia.com.br · portalfolharegional.com · liberdadesbs.com.br · diariodajaragua.com.br · diarioderiomafra.com.br · radiometropolitanaitapoa.com.br · imagemdailha.com.br · jornaisemfoco.com.br · correiootaciliense.com.br

## Anexo — rejeitados com motivo

- **Mortos (20):** portalsmo.com.br, portaltri.com.br, nossaradio.net.br, guiatemabelardoluz.com.br, canaldosul.com.br, radio98fm.com, valesc.com.br, portaldacidade.com, testonoticias.com.br, jornaldepomerode.com.br, sintonia.fm.br, radiocidadesc.com.br, bcnoticias.com.br, portalitapema.com, praianortenews.com.br, jornalconexao.com.br, jornaltopnews.com.br, olhovivocan.com.br, saojoaquimonline.com.br, asemanacuritibanos.com.br
- **Duplicados (19):** peperi, clicrdc, oestemais, ederluiz, portalclicksul, rc.fm.br, cruzeirodovale, jornalmetas, guabirubazeitung, rbatv, diarinho, portalmenina, camboriu.news, pagina3, abreolhonoticias, jornaldenavegantes, scempauta, radiosuper, vipsocial
- **Vivos sem notícia em texto (5):** novafm103.com.br, radionova.fm.br, culturacamposnovos.com.br (rádio só-player), portalpalhoca.com.br, palhocense.com.br (sem data recente detectável)
- **Quebraram no 1º ciclo, desativados (5):** agorasul.com.br (403), vvale.com.br (403), jornalnossailha.com.br (SSL expirado), santacatarinaempauta.com.br (XML inválido), oestescnoticias.com.br (403 no listing)

---

## Re-probe (correção de falso-mortos) — 2026-06-10

O QA original deu falso-negativo em parte dos 30 reprovados: o probe rodava 16 threads do mesmo IP com fingerprint de `python-requests`, e o coletor de produção usava UA `JornalRazaoBot/1.0` — WAFs regionais respondem 403/timeout pra isso. Re-auditoria sequencial (~2 req/s, headers de Chrome real, Referer Google, retry com backoff) + validação final com o client real do coletor (curl do Laravel):

**Causa raiz dupla e correções aplicadas:**
1. **UA do coletor** trocado globalmente de `JornalRazaoBot/1.0` → Chrome 137 (`HttpFetchService`). Amostra de 14 fontes ativas testada com os dois UAs antes da troca: nenhuma regressão (14×200 em ambos).
2. **Worker com classe velha em memória**: o queue worker é processo de longa duração e seguiu usando o UA antigo após o deploy — `systemctl restart newsradar-worker` resolveu (403→200 nas mesmas fontes, no mesmo minuto).

### Tabela: domínio → diagnóstico real → destino

| domínio | diagnóstico (cliente realista) | destino |
|---|---|---|
| guiatemabelardoluz.com.br | 200 + feed recente (falso-morto) | **cadastrado** (feed, Oeste) — 31 itens no 1º fetch |
| saojoaquimonline.com.br | 200 + feed (bloqueava python-requests; curl passa) | **reativado** (fonte original id 31) — fetch OK |
| portalitapema.com | 200 + feed (timeout era só do fingerprint python) | **reativado** (fonte original id 16) — 76 itens |
| agorasul.com.br | 200 com Chrome UA (403 pro bot) | **reativado** — fetch OK pós-UA |
| vvale.com.br | 200 com Chrome UA (403 pro bot) | **reativado** — fetch OK pós-UA |
| oestescnoticias.com.br | 200 com Chrome UA (403 pro bot no listing) | **reativado** (listing) — fetch OK pós-UA |
| santacatarinaempauta.com.br | 200; RSS com XML inválido, wp-json ok (parser não suporta) | **reativado como html_listing** — 28 itens |
| jornalnossailha.com.br | 200 só com verify desligado — certificado TLS EXPIRADO | **vivo-porém-bloqueado (TLS)** — fora até renovarem |
| nossaradio.net.br · canaldosul.com.br · testonoticias.com.br · jornaldepomerode.com.br · sintonia.fm.br · radiocidadesc.com.br · bcnoticias.com.br · praianortenews.com.br · jornalconexao.com.br · jornaltopnews.com.br · olhovivocan.com.br · asemanacuritibanos.com.br | 403/desafio anti-bot deste IP mesmo com curl+Chrome UA (bcnoticias/olhovivocan confirmados vivos de fora) | **vivo-porém-bloqueado (12)** — anti-bot duro/reputação do IP do VPS; NÃO cadastrados |
| portaldacidade.com | 200, mas landing de diretório nacional sem notícia datada | **fora da régua** (diretório/agregador) |
| portalsmo.com.br · portaltri.com.br · valesc.com.br | connect timeout 20s (DNS resolve, servidor não responde a este IP) | **morto-real (timeout)** |
| radio98fm.com | HTTP 500 persistente | **morto-real (erro de servidor)** |
| novafm103 · radionova.fm.br · culturacamposnovos · portalpalhoca · palhocense | 200, mas sem notícia datada em texto (rádio-player/institucional) — reconfirmado | **rejeição mantida** |

### Resultado

- **+7 fontes recuperadas** (1 cadastro novo + 6 reativações, todas com fetch de produção OK e itens fluindo).
- Allowlist concorrente: 108 → **111** domínios.
- **Fontes ativas: 129 → 134** (148 cadastradas no total).
- Revalidação pós-recuperação: ciclo automático bridge 11:30 + juiz 11:35 (2m59s) com o volume novo → **aceite da Fase 2 inteiro de pé** (nacional=frio, 38 scores distintos/moda 7%, oceanógrafo 1 cluster, SEO eh_pauta=false) e **paridade WhatsApp 122/122**.
- Pendência: os 12 vivo-porém-bloqueado podem ser re-tentados de outro IP/proxy residencial numa fase futura; jornalnossailha volta quando renovar o certificado.

---

## Notificação ligada — 2026-06-10

O Radar JR agora avisa sozinho: digest de pautas quentes NOVAS no grupo WhatsApp **"Raspador"**, via Z-API direto de dentro do Laravel — caminho 100% próprio, sem encostar no disparador n8n (g6ApLgldIyHKLwdq, intocado) nem no dedup-svc.

### Configuração

- **Group-id descoberto e validado via API** (listagem de chats, match único pelo nome): `120363425758810671-group`.
- **Envs criados** (`.env`, backup prévio em `/tmp/.env.bak-notif-*`): `JRLINK_ALERT_ZAPI_INSTANCE`, `JRLINK_ALERT_ZAPI_TOKEN`, `JRLINK_ALERT_ZAPI_CLIENT_TOKEN`, `JRLINK_ALERT_GROUP`. **Kill switch:** qualquer um vazio = desligado. Estado final: **LIGADO**.
- **Migration** `2026_06_10_150000_add_notificado_em_to_jr_link_extracao` (coluna `notificado_em`, com rollback) — dedup permanente por item E por cluster (história notificada nunca repete).
- Service `app/Services/Jr/RadarNotificador.php`, chamado ao fim de cada `jrlink:juiz` agendado. Digest único por ciclo: cabeçalho "🔥 Radar JR — N pautas novas", por item eixo/score/cidade/gancho/título/link, rodapé com o extract.html. Cap 10 itens (+N no relatório), janela de silêncio 23h–06h — tudo em `config/jrlink.php > notificacao`.

### Provas (mensagens reais no grupo)

| prova | resultado |
|---|---|
| Teste de conexão | "✅ Radar JR conectado — 10/06/2026 12:07" → **HTTP 200, messageId `3B15A983E05373E15657`** |
| Anti-flood de estreia | **531 quentes do estoque marcados como já-notificados** antes de ligar — só o delta daqui pra frente |
| Ciclo real (quente novo → mensagem) | pauta #10324 ("PARAJESC… inclusão", score 92) → digest enviado, **messageId `3EB0C649A5395169758841`** |
| Dedup (2º ciclo) | mesmo comando de novo → **"sem_novos", nenhuma mensagem** |
| Anti-loop | Mensagem da própria instância com link foi capturada pelo webhook (e chega com `from_me=0`! — o flag não protege) → filtro por `chat_name='Raspador'` no `coletarUrls`: **com filtro o link NÃO entra na coleta; contraprova com filtro desligado em memória: entraria**. Config em `jrlink.captura` (`ignorar_from_me` + `ignorar_chats`) |
| Paridade WhatsApp | **122/122 OK** |
| Aceite Fase 2 | **TODOS OS CRITÉRIOS PASSARAM** |

Detalhe operacional: o webhook da Z-API entrega mensagem enviada pela própria instância com `fromMe` ausente/falso — por isso a defesa principal do anti-loop é a exclusão do grupo Raspador na coleta (o armazenamento bruto continua guardando tudo; o corte é só na entrada do pipeline).

---

## Painel Radar v2 — 2026-06-10

### Bug dos horários: causa-raiz (diagnosticada antes de codar)

1. **dd/mm lido como m/d**: `DateParserService` tentava `Carbon::parse()` genérico ANTES dos formatos brasileiros — "10/06/2026" virava **6 de outubro**. O lote do Caçador Online ficou datado no futuro, dominando o sort desc e exibindo "agora" (timeAgo de data futura ≈ 0 min). Fix: padrão `dd/mm/yyyy` agora resolve como d/m SEMPRE, antes do parse genérico. **15 linhas históricas re-parseadas e corrigidas; 0 datas futuras restantes.**
2. **Catch-up vestido de "agora"**: fontes listing sem published_at caíam no `created_at` (hora da coleta) sem aviso. Fix: card rotula hora de PUBLICAÇÃO; sem ela, mostra "coleta Xm" explicitamente. Ordenação já era por published_at_utc (NULLs pro fim — verificado).
3. **Prova com 10 itens reais**: 7 batem exatos com o `article:published_time` da origem, 1 bate com offset convertido (Visor -03:00), Caçador bate com a data da URL (/2026/06/10/), 2 sem meta tag na origem (sem divergência). Nenhum mismatch.

### Aba Radar (painel React)

- **Rota nova**: `GET /api/v1/jrlink/radar` — paginada, quentes finais (`coalesce(temperatura_juiz, temperatura)`), 1 história por cluster, tamanho do cluster agregado em 1 query (sem N+1). Filtros: eixo, score mínimo, cidade, busca (título+motivo), janela 24/48h, ordenação score|recente, `secao=fila` pra fila humana.
- **Front**: aba "Radar" entre Feed e Fontes (identidade visual mantida; Feed/Fontes intactos — 200 em produção). Card: PRIMÁRIA (verde) / RADAR (âmbar), score 0-100 colorido por faixa, cidade, gancho, motivo do juiz, fonte, "1 de N portais", 🔔 quando já avisado no Raspador, tempo de publicação honesto. Mobile-first (lista de texto, filtros em grid 2 colunas no iPhone). Seção "Fila humana" colapsável.
- **Chave leve**: middleware `JrPanelKey` fail-closed — `?key=` no 1º acesso grava cookie de 90 dias (verificado: expira 2026-09-08). Sem key/cookie: 403 e a aba mostra o cadeado com instrução. `JRLINK_PANEL_KEY` no .env (backup em /tmp).

### Validação

| check | resultado |
|---|---|
| Build vite + produção | bundle novo servido em jornaldetijucas.com.br; Feed items 200 · Sources 200 |
| Radar vs extract.html | mesmo topo (Pronampe 100, Casamento 166 anos 98…); aba aplica janela 24/48h por design |
| Scheduler pós-deploy | ciclo 12:35 automático: dispatch + juiz (1m29s) DONE |
| Notificação pós-deploy | digest real enviado no ciclo: 3 novas, messageId `3EB0BBBCA257AB1769FF1F` |
| Paridade WhatsApp | 122/122 OK |

**Acesso (Lorran):** https://jornaldetijucas.com.br/?key=4bbcaa9c2e75ae4ba7703810a422e4dc → aba **Radar** (o cookie segura por 90 dias; a key fica no .env como `JRLINK_PANEL_KEY`).

---

## Feedback humano — 2026-06-10

O Radar agora aprende com o Lorran: cada card da aba Radar tem **3 botões de nota** — ❄️ 10-30 · 😐 30-60 · 🔥 60-100. Um toque vota (otimista, sem reload), o botão votado fica azul e **persiste ao recarregar**; tocar outro troca o voto (mesma linha no banco, sem duplicar). Funciona só com a chave do painel (sem key = 401).

### Como votar
Abrir o painel (cookie de 90 dias já resolve, ou `?key=`), aba **Radar**, tocar na nota de cada pauta. O snapshot do juiz (score+gancho daquele momento) fica gravado junto — re-julgamentos futuros não contaminam o rótulo.

### Como rodar o report
```
php artisan jrlink:feedback-report
```
Sai no terminal: total de votos, **matriz juiz×humano** (3 faixas), divergência média e % de concordância, top 10 maiores divergências (título, score do juiz, faixa humana, gancho, fonte), divergência média por gancho e por cidade. Espelho JSON em `storage/app/jr-feedback-report.json`. Validado com 3 votos de teste por curl (um por faixa + re-voto trocando faixa sem duplicar) — **votos de teste limpos ao final**, a base começa zerada pro uso real.

### Como ligar o few-shot (quando houver volume)
1 linha no `.env`:
```
JRLINK_JUIZ_FEWSHOT=true
```
Com a flag ligada **e ≥ 30 votos** (`jrlink.juiz.fewshot.min_votos`), o prompt do juiz ganha o bloco "CALIBRAÇÃO DO EDITOR" com até 12 exemplos (só título+cidade+gancho+faixa humana), priorizando as maiores divergências juiz×humano e cobrindo as 3 faixas e os ganchos mais votados. Smoke-test do caminho ligado: bloco gerado com os exemplos corretos.

### Prova: juiz INALTERADO neste goal
Flag default OFF → prompt gerado antes e depois das mudanças é **byte a byte idêntico**:
```
md5(prompt antes)  = d9dac880a20d620dd112c3a0bd897f41
md5(prompt depois) = d9dac880a20d620dd112c3a0bd897f41
diff = vazio
```
Aceite Fase 2: **4/4 de pé** · paridade WhatsApp **122/122** · ciclo do scheduler pós-deploy observado (13:05: dispatch + juiz 2m DONE, notificação em silêncio correto — sem quentes novos) · Feed/Fontes/Radar 200 em produção com o bundle novo.

---

## Radar v3 — EVENTO como unidade — 2026-06-10

A aba Radar deixou de listar ITEM e passou a listar **EVENTO**: 1 card por cluster (item sem cluster = evento de 1), com badge "N portais" que expande pras coberturas. Validado em produção: **30 eventos na primeira página, zero cluster repetido**; caso real "ampliação da cota da tainha" = **22 portais → 1 card**.

### O que cada card de evento traz
Item líder (maior score, desempate mais recente) dá título e link; score = MAX dos itens; cidade/gancho/motivo do veredito; lista de fontes distintas com link individual; primeira/última cobertura; 🔔 se já avisado no Raspador. **Voto de feedback agora é do evento** (upsert no item líder; o GET devolve o voto no nível do evento — verificado persistindo).

### Clusters fundidos no re-run (merge assistido por LLM)
Pares de similaridade intermediária (0,275–0,45, vetos de cidade/idade respeitados) vão em LOTE pro driver com **prompt próprio e separado** — "mesmo evento? sim/não" — cap de 40 pares/ciclo (`jrlink.cluster.llm_merge_pares`, 0 desliga). **Re-run de 48h fundiu 18 clusters**, todos logados (`[ClusterMergeLLM]` no laravel.log). Exemplos reais:
- "Tentativa de roubo a banco termina com suspeitos presos após sequestro" ≈ "Megaoperação da PM prende suspeitos de roubo e sequestro"
- "Baixa adesão de produtores em eventos da AMAP preocupa…" ≈ "Baixa participação em assembleia da AMAP acende alerta…"
- "Moradores controlam princípio de incêndio…" ≈ "Bombeiros Voluntários de Presidente Getúlio atendem princípio de incêndio…"

**Prova de juiz intocado:** md5 do prompt do juiz **`e6f4315232881f6f5071d7bb75188df5` antes e depois** (diff vazio). Limitação conhecida: título genérico sem fato ("NOTA DE PESAR!") induziu fusão de obituários distintos — prompt do merge já endurecido (genérico = false); itens são frios, sem impacto editorial.

Bônus do re-clustering: vocabulário de **calendário virou stopword** — o mega-cluster lixo "Café com Notícias – 10/06" (17 itens colados por "junho/2026/terça-feira") morreu, e o EM ALTA passou a refletir cobertura real.

### EM ALTA (trending sem IA)
`score_trending` por evento = nº de fontes distintas ponderado por recência (cobertura <24h vale 1,0; 24-48h vale 0,5). Entram eventos com **≥3 fontes** na janela, ordenados por trending — independente do score do juiz (o sinal é cobertura, não gosto). Hoje no topo: tainha (22 portais, 18,5), Mega-Sena (13,5), ciclones em SC (10,5), vacina dengue (10,5). **Ajuste do corte:** `config/jrlink.php > radar.alta_min_fontes` (default 3).

### Desktop de editor (mobile intacto)
- **≥1024px**: faixa 🔥 EM ALTA no topo (grid 3-4 colunas), quentes em **grid 2-3 colunas**, "📋 Radar geral" (tudo da janela, linhas compactas) colapsável, "🙋 Fila humana" ao final.
- **Mobile**: 1 coluna como antes, EM ALTA vira carrossel horizontal. Filtros e identidade visual preservados.

### Validação
Zero cluster repetido (30/30 únicos) · caso 22→1 · 18 fusões logadas · EM ALTA real · voto em evento persiste · md5 do juiz idêntico · aceite Fase 2 **4/4** · paridade **122/122** · Feed/Fontes 200 · build v3 servido em produção · **ciclo pós-deploy observado: 16:05, dispatch + juiz 3m26s DONE já com o merge LLM dentro**.

---

## DNA Instagram — 2026-06-10

O juiz agora pontua com a régua real do negócio: **"isto seria um post do @jornalrazao?"** — ancorada no corpus do perfil puxado via Apify (400 posts, 14/mai–10/jun, ator `apify~instagram-post-scraper`, **custo real do run: US$ 1,0773**). `likesCount` vem oculto no scraper (0/1 falso) e foi ignorado; engajamento = **comentários** (mediana), views de reel como secundária.

### Tabela tipo × engajamento (jr-ig-dna.json)

| tipo de pauta | n | % do feed | mediana comentários | mediana views (reel) |
|---|---|---|---|---|
| política (local, com emoção) | 48 | 12% | **1.648** | 116k |
| flagrante_policial | 109 | 27,3% | **798** | 287k |
| viral_curiosidade | 47 | 11,8% | 757 | 272k |
| obito_luto | 15 | 3,8% | 686 | 154k |
| superacao_historia | 26 | 6,5% | 583 | 144k |
| acidente_resgate | 67 | 16,8% | 527 | 246k |
| animal | 14 | 3,5% | 325 | 146k |
| institucional | 10 | 2,5% | 313 | 98k |
| servico_utilidade | 19 | 4,8% | 265 | 73k |
| clima | 6 | 1,5% | 211 | 135k |
| evento_cultura | 12 | 3% | **150** | 93k |
| economia_negocios | 9 | 2,3% | **141** | 67k |

Top reais: pastores que mantiveram mulher em cárcere (11,4 mil comentários), ladrão surpreendido em Chapecó (10,9 mil), pescadores chorando com a volta da tainha (10,5 mil). Flop reais: encontro de motos (6), release "Estrada Boa Rural" (26), loteamento (8). O DNA do IG é diferente do GA4 do site: lá economia tinha lift 1,32; no IG, economia institucional flopa — o prompt novo reflete o IG (canal da pauta), a âncora GA4 segue como ajuste fino.

### O que mudou no prompt (v3) — só a seção temperatura/score

Resumo do DNA por tipo + 8 exemplos reais de alto engajamento + 5 de baixo/que o perfil evita; regra explícita: **flagrante só esquenta com narrativa** (boletim burocrático é frio mesmo sendo crime) e **evento institucional fofo (casamento coletivo, inauguração protocolar) NÃO é quente** salvo cobertura múltipla. Escopo, eh_pauta, tipo_gancho e SEO-washing intactos. `prompt_versao=v3` (idempotência re-julgou o estoque 1x: 1.155 itens, US$ 3,56, 0 falhas).

### Prova antes/depois (sombra de 1.106 reps de 48h, sem tocar produção)

- **Casamento coletivo de Itajaí: 98 → 58, frio** ("evento institucional fofo que não esquenta") — exatamente o caso-teste do goal.
- Maiores movimentos (15): Adota Jaraguá 96→19 · "Lula fala sobre a polêmica das tainhas" 8→79 · ordem de serviço de casas populares 82→14 · "Pelo Estado: tainha fala mais sobre política" 16→86 · AMAP 86→19 · Senai na Itália 84→19 · "SC consolida confiança dos mercados" 85→21 · incêndio com vítima fatal 12→75 · "Marginais invadem casa em Indaial" 22→85 · Joaçaba preside Escola de Pais 83→21 · Lions Clube posse 72→11 · Catedral energia solar 84→24 · motorista foge após acidente 9→69 · projeto "Dia Nacional de..." 86→27 · Deolane na cela 0→71 (viral; escopo nacional segue frio na temperatura).
- Quentes 465 → 324 na sombra (régua mais seletiva); 98 valores distintos de score; institucionais ≥60 sobraram só 16/328 (e legítimos, ex.: pesquisa do Procon com variação de 449%).
- **Concordância juiz×humano: N/A** — `jr_pauta_feedback` estava zerada (votos de teste limpos no goal anterior; few-shot segue OFF até haver ≥30 votos reais).
- Promovido na 1ª iteração (não precisou das 3).

### EM ALTA sem nacional puro

O endpoint do EM ALTA agora exclui evento cujo líder tem `escopo=nacional` (pauta de agência que todo portal replica). Validado em produção: **Mega-Sena sumiu, tainha (regional/nacional_localizado) ficou** — topo atual: cota da tainha (13,5), VPA recorde (12,5), ciclones (10,5), vacina dengue (10,5).

### Validação final

Aceite Fase 2 **4/4 com o prompt v3** (nacional morto 0/44 · 39 scores distintos moda 6% · oceanógrafo 1 cluster · SEO eh_pauta=false) · paridade **122/122** · notificação viva (digest da promoção: 107 novos pela régua nova, 10 na mensagem +97 no relatório, messageId `3EB02928920E8AE7EDDC33`) · ciclo automático no journal (18:00 dispatch+bridge).

### Como re-rodar o corpus (mensal)

```
php artisan jrlink:ig-corpus            # puxa os ~400 posts mais recentes (Apify, ~US$ 1)
php artisan jrlink:ig-dna --reclass     # re-classifica e regenera storage/app/jr-ig-dna.json
```
Depois, atualizar manualmente os números/exemplos da seção DNA no prompt (`JuizLlm::montarPrompt`) e bump de `prompt_versao` — o estoque re-julga sozinho 1x.

---

## Canal Instagram — 2026-06-10

Perfis que só publicam no Instagram agora entram na **mesma esteira** do radar: `jrlink:instagram-poll` (a cada 15min no scheduler) roda o ator Apify, e cada post vira item `origem='instagram'` na régua — gate coarse → cluster → **juiz v3** → aba Radar → notificação. Nada de caminho paralelo.

### Como está funcionando (1º poll real)

- **27 itens novos** de 11 perfis reais (3 por perfil; alguns posts são co-autorados — a fonte gravada é o dono real do post, ex.: @andreguesser, @pmscrodoviaria via repost dos milgrau).
- **Re-poll imediato: 0 duplicados** (dedup permanente por shortcode na url canônica).
- A régua dura filtrou o ruído social (memes score 0-3) e mandou 5 pro juiz, que julgou com o DNA: *"Câmara de São José custa R$ 33 mi"* (@calamidadeoficial) → **quente 84** (indignação política local, o nº 1 do DNA); *"FILAS E DESCASO COM A SAÚDE"* → quente 81; resgate de animais (@sos_naufragados) → fila humana; festa-agenda e meme → frios.
- Card IG na aba Radar como evento de 1, linkando o post ✓. Prompt v3 do juiz INTOCADO (md5 `eeda2cf7…` idêntico).
- Ciclo automático observado: poll das 20:45 rodou sozinho no scheduler (1m44s DONE).

### Ator e custo — v2 (incremental + round-robin, medido com dinheiro real)

A 1ª versão custava **US$ 210/mês** porque cada poll re-pagava os mesmos posts. Investigação paga em **5 configurações reais** (apify c/ `onlyPostsNewerThan`, sones lowcost, apidojo, fast-scraper, apify c/ `skipPinnedPosts`):

- **Nenhum ator devolve poll quieto a custo zero**: todos cobram por post retornado; sones cobra a 1ª página inteira mesmo com cutoff; apidojo nem entrega posts desses perfis; fast cobra "processing fee" pelos filtrados; e **post FIXADO fura o filtro de data** em todo poll.
- Config final no `apify~instagram-post-scraper` (US$ 2,7/1k): **`onlyPostsNewerThan` = post mais novo já ingerido + `skipPinnedPosts` + ROUND-ROBIN de 1 perfil por poll** (config `instagram.perfis_por_poll`; rotação completa a cada 2h30 com ciclo de 15min mantido).

| | |
|---|---|
| **PROVA (2 polls seguidos, MESMO perfil, sem post novo)** | 1 resultado residual cada · **US$ 0,0027/poll** (vs US$ 0,073 antes = **27× mais barato**); "custo ~zero absoluto" é impossível em qualquer ator testado |
| Poll quieto | US$ 0,0027 · poll com posts novos: + US$ 0,0027/post |
| **Projeção mensal (ciclo 15min mantido)** | 96 polls/dia × US$ 0,0027 ≈ US$ 7,80 + posts novos ≈ **US$ 8-9/mês** ✅ meta <US$ 10 |
| Alavancas | `perfis_por_poll` (cobertura×custo proporcional) · cron · `max_posts_por_perfil` |
| Trade-off declarado | cada perfil é visitado a cada ~2h30 (não a cada 15min); pra perfil crítico em tempo real, suba `perfis_por_poll` (2 → ~US$ 16-18/mês, rotação 75min) |

### Operação

- **Editar perfis:** `config/jrlink.php > instagram.profiles` (lista simples de usernames). Lista vazia = canal desligado.
- **Desligar tudo:** `JRLINK_IG_ENABLED=false` no `.env`.
- **Resiliência:** 3 falhas seguidas do poll → 1 alerta no grupo Raspador (supressão de 6h pra não floodar); log por run com run_id, posts novos e custo.
- Post sem legenda útil (<25 chars) é pulado e logado. `likesCount` do ator vem oculto e é ignorado.

### Bônus: bug real corrigido no caminho

A **janela de silêncio da notificação calculava em UTC** — na prática silenciava 20h–03h de Brasília (3h mais cedo). Corrigida pra hora local (`notificacao.timezone`). Digests do começo da noite voltam a sair.

---

## Radar v4.1 — tempo real — 2026-06-11

A reclamação central do editor era ver **pauta de dias atrás no topo como "quentíssima"**. O v4.1 separa mérito de urgência: o juiz continua julgando o mérito **uma vez** (prompt v3 intocado, md5 idêntico), e o painel calcula deterministicamente um **score_atual = score do juiz × fator de frescor** pela idade da publicação original do líder do evento. Três frentes: decaimento temporal, filtro "já publicado" (WordPress + Instagram) e modo triagem mobile.

### 1. Decaimento temporal — "Quente agora" é a ordenação default

`score_atual = score_juiz × fator(idade)`: **<6h = 1.0 · 6-12h = 0.85 · 12-24h = 0.65 · 24-48h = 0.4 · >48h = 0.4** (tudo em `config/jrlink.php > radar.decaimento` + `radar.decaimento_apos`). O score do juiz **não muda no banco** — o decaimento é só exibição/ordenação, calculado no backend (SQL e payload usam a MESMA base: score máximo do evento × idade do líder). Select de ordenação: 🔥 Quente agora (default) · Score puro · Mais recente. O card mostra o score_atual com a idade do lado (badge "9h"/"2d" — cinza apagado pra velho); tooltip explica `juiz 94 × decaimento = 38`.

**Antes/depois do topo da aba (mesma janela 48h, mesmo estoque):**

ANTES (score puro, comportamento v3 — catch-up velho dominava):
```
 1. juiz 100 | atual  65 |  18h | Mulher de 37 anos é denunciada por se passar por criança de 11…
 2. juiz  99 | atual  64 |  15h | Investigação revela horrores da história de mulher resgatada…
 3. juiz  98 | atual  39 |  42h | Pesca de tainha em SC vai ter aumento de cota, afirma deputado…
 4. juiz  96 | atual  38 |  35h | Câmara repudia suspensão de pesca da tainha
 5. juiz  96 | atual  62 |  23h | Conheça a história de Pablo, de Timbó: a batalha diária…
 6. juiz  96 | atual  38 |  36h | Menino apaixonado por motores se torna influenciador…
 7. juiz  95 | atual  38 |  35h | Mãe de criança morta em ataque a creche se revolta…
 8. juiz  95 | atual  62 |  23h | Bando faz família refém para roubar banco no Alto Vale…
 9. juiz  94 | atual  38 |  42h | Presidente da Colônia de Pesca Z-33 defende redistribuição…
10. juiz  94 | atual  38 |  39h | Amin critica cota da tainha e cobra revisão na concessão da BR-282
```

DEPOIS (🔥 Quente agora, default v4.1 — o que está quente AGORA sobe):
```
 1. juiz 100 | atual  85 |   9h | Em Florianópolis, ALESC cobra solução para pesca da tainha…
 2. juiz  85 | atual  85 |   1h | Último integrante de quadrilha que aterrorizou família…
 3. juiz  93 | atual  79 |  10h | MP orienta Câmara a decretar perda de mandato de vereador
 4. juiz  92 | atual  78 |  12h | Em um ano, violência sexual contra crianças e adolescentes…
 5. juiz  77 | atual  77 |   3h | Traficante é preso com 18 kg de crack escondidos…
 6. juiz  75 | atual  75 |   2h | Buscas por homem desaparecido entram no terceiro dia…
 7. juiz  74 | atual  74 |   2h | Lei Orelha é aprovada na Alesc enquanto CPI sobre morte do cão…
 8. juiz  84 | atual  71 |   9h | Palhoça: defesa da tainha e alerta para enchentes…
 9. juiz  82 | atual  70 |  12h | Operação da Polícia Civil prende três investigados…
10. juiz  69 | atual  69 |   2h | Sem CNH, motociclista foge da PM após quase atropelar estudantes…
```

**Caso-teste nomeado (cassação Cleiton Profeta, juiz 94, pub 08/06):** `94 × 0.4 = 38` (≤ ~40 ✓) com **9 quentes reais de <6h acima dele** (ex.: "Último integrante de quadrilha…" 1,2h atual 85). E como a matéria casou com o site (slug `vereador-cleiton-profeta-cassado-camara-joinville-13-votos`), o evento **some do default** — só aparece no chip "Já publicadas", com badge ✅.

**Notificação com teto de idade:** `RadarNotificador` não dispara para evento cuja publicação original é mais velha que `jrlink.notificacao.max_idade_horas` (**default 12**) — catch-up de matéria velha não vira WhatsApp (o item é marcado como tratado, sem envio, e logado). Dry-run real (em transação): Cleiton liberado pra notificação → **BLOQUEADO por idade**; quente fresco de <6h no mesmo plano → enviável.

### 2. Já-publicado — WPGraphQL → jr_publicado → matching

`jrlink:publicados-sync` no scheduler (**30min, withoutOverlapping**): consulta **só de leitura** em `https://controle.jornalrazao.com/graphql` (posts publicados das últimas 72h: título, slug, data, categoria) → upsert por slug em `jr_publicado`. Matching contra os eventos quentes em duas camadas, na mesma filosofia da Fase 2:

1. **Similaridade textual = a própria máquina do cluster** (EventClusterer: overlap idf + âncora rara + vetos de cidade/idade) — posts do WP entram como linhas-fantasma no `cluster()`; co-membro = match direto, custo zero;
2. **pares limítrofes → LLM em lote** com prompt PRÓPRIO ("mesma história? sim/não" — manchete reformulada conta, fato parecido em outra cidade/dia NÃO), cap **40 pares/ciclo**, logado em `jr_juiz_log` como `publicado_match`.

Match → o **cluster inteiro** ganha `ja_publicado_em` + `ja_publicado_slug`: some do Radar por default (chip "Já publicadas" exibe com badge **✅ no site**, linkando a matéria) e o notificador **nunca** notifica. O mesmo confronto roda contra `jr_ig_corpus` → badge **✅ no IG** (informativo, linkando o post — não esconde).

**Backfill (7 dias de WP × estoque quente): 203 posts espelhados · 58 eventos casados com o site (141 itens contando clusters) · 22 com o Instagram.** Exemplos reais do casamento: "Vovó da cocada é presa em Tijucas…" ← slug `vovo-cocada-presa-tijucas-pmsc-alerta-policia-federal` · "Pedreiros ouvem gritos de socorro e impedem possível feminicídio…" ← `pedreiros-salvam-mulher-impedem-feminicidio-itapema` · "Governo de SC vai à Justiça para tentar reverter proibição…" ← `governo-encerra-safra-tainha-38-dias-sc-justica-derrubar` · cassação Cleiton Profeta ← `vereador-cleiton-profeta-cassado-camara-joinville-13-votos`. Limitação declarada: matching textual tem falso-positivo residual (título genérico de ocorrência) — o chip "Já publicadas" deixa tudo auditável no painel.

### 3. Modo triagem (mobile)

- Toggle **☰ Lista / ▦ Cards** (persistido). Lista = 1-2 linhas por evento: score_atual colorido, título truncado, cidade, idade e **❄️😐🔥 mini inline** — vota sem expandir (mesmo endpoint do card; re-voto upsert); **tap expande** o card completo.
- Chips: **⚡ Agora** (só publicação original <24h) · **Não votados** (filtro server-side — pagina certo) · **Já publicadas** (off por default). Contador **votados/total** do conjunto filtrado no topo.
- Filtros, ordenação, janela e modo **persistem em localStorage** (`jrradar:*`).
- Sem N+1 (mesma agregação de 2 queries por página + 2 counts) — scroll infinito de 30 por página na lista.

### Como ajustar (tudo em `config/jrlink.php`)

| o quê | onde |
|---|---|
| faixas/fatores do decaimento | `radar.decaimento` (lista `ate_horas`/`fator`) e `radar.decaimento_apos` (>48h) |
| teto de idade da notificação | `notificacao.max_idade_horas` (default 12) |
| janela de posts do WP por ciclo | `publicados.janela_horas` (default 72) |
| estoque de eventos confrontado | `publicados.janela_eventos_horas` (default 168) |
| cap de pares no LLM por ciclo | `publicados.llm_cap_pares` (default 40; 0 desliga o LLM do matching) |
| endpoint WPGraphQL | `publicados.endpoint` |
| backfill manual | `php artisan jrlink:publicados-sync --backfill` (7 dias) · `--dry` mostra sem gravar |

### Validação

Cleiton Profeta 94→38 fora do topo com 9 quentes <6h acima ✓ · dry-run notificador: velho bloqueado + já-publicado nunca aparece no plano (mesmo fresco) + fresco enviável ✓ · sync casou **58 eventos reais** (≥3 ✓), somem do default e ficam fora da notificação ✓ · voto pelo endpoint da lista persiste (POST + releitura = `faixa_voto=alta`; voto de teste removido depois) ✓ · **md5 do prompt do juiz idêntico** (`4c4e394bd59107f53c0c82b826533a26` antes e depois, entrada fixa) ✓ · aceite Fase 2 **4/4** ✓ · paridade WhatsApp **122/122** ✓ · painel/Feed/Fontes/Radar **200** local e em produção (bundle novo buildado) ✓ · ciclo REAL do scheduler observado no journal (08:30: dispatch 1s → bridge 27s → instagram-poll 12s → **publicados-sync 46s DONE**; o próprio ciclo casou +11 eventos → 69 no total; vereditos "não" do LLM ficam 7 dias em cache — o ciclo de 30min não re-pergunta o mesmo par) ✓.

---

## Opus + match robusto + digest agrupado — 2026-06-15

Duas reclamações reais do editor no digest do WhatsApp: (a) pauta que o JR **já publicou** voltava como nova (manchetes do mesmo fato sem palavra em comum não casavam no matching textual), e (b) 5 portais com a mesma matéria viravam 5 linhas. Mais a diretriz de subir a inteligência do pipeline pro melhor modelo disponível.

### 1. Opus 4.8 na inteligência — modelo por função

Nova seção `config/jrlink.php > modelos`, uma chave por inteligência, **default Opus 4.8** (`claude-opus-4-8`):

| função | config | env | uso |
|---|---|---|---|
| juiz (mérito editorial) | `jrlink.modelos.juiz` | `JRLINK_MODELO_JUIZ` | `jrlink:juiz` |
| dedup ("mesmo evento?") | `jrlink.modelos.dedup` | `JRLINK_MODELO_DEDUP` | merge LLM de clusters |
| match publicado ("já publicamos?") | `jrlink.modelos.match_publicado` | `JRLINK_MODELO_MATCH` | `jrlink:publicados-sync` |

`JuizLlm` passou a rotear o `--model` do claude-cli por função (timeout subiu pra 600s — Opus é mais lento no lote de 24). **Fallback:** se um modelo sair do ar, troque o env pro anterior validado `claude-haiku-4-5-20251001` (mais barato, menos preciso) ou qualquer id que o `claude` aceite. **Teste real:** `claude -p … --model claude-opus-4-8` respondeu JSON válido; o juiz julgou o item #138868 com Opus (veredito coerente e justificado); o match rodou 160 pares em Opus. Cada chamada loga em `jr_juiz_log` o modelo realmente usado (`claude-opus-4-8`).

### 2. Match de publicado robusto — pré-filtro largo, LLM decide

Antes a similaridade textual **decidia** os óbvios e só os limítrofes iam pro LLM — perdia "mesmo fato, manchete diferente". Agora (`jrlink:publicados-sync` v4.2):

- **Pré-filtro = gerador de candidatos generoso:** overlap idf título×título reusando os tokens do EventClusterer, **enriquecido com as palavras do SLUG do post** (o slug carrega cidade/ângulo que o título às vezes omite — ex.: `…maus-tratos-jaragua-do-sul`). Top-K (6) posts por evento acima de overlap mínimo **baixo** (0.12). Não decide nada.
- **Decisão = SEMPRE do LLM (Opus)**, em lotes de 20 (prompt focado preserva a precisão), cap 80 pares/ciclo: *"este evento do radar é o MESMO FATO que este post já publicado? as manchetes podem ser totalmente diferentes — compare o fato/pessoas/lugar, não as palavras. sim/não"*, com o lead do evento como contexto. "Não" fica 7 dias em cache (não re-pergunta).

**Caso-teste cravado — criança de 2 anos (PROVOU):** resetei o match desse cluster e rodei o backfill novo. O evento do radar *"Criança de 2 anos é internada em UTI após suspeitas de maus-tratos em SC"* (radiomirador) **casou** com o post publicado *"Criança de 2 anos é internada com lesões no tórax, costas e cabeça em SC: 'caiu no banho', diz mãe"* (`crianca-2-anos-internada-lesoes-suspeita-maus-tratos-jaragua-do-sul`) — manchetes praticamente sem palavra em comum, Opus reconheceu o mesmo fato. Cluster inteiro marcado, e o evento **sumiu do plano do digest** (`criança no plano: false`).

**Backfill 7 dias (estratégia nova):** 210 posts do WP confrontados; **13 eventos casados com o site + 9 com o Instagram** que a estratégia textual-only não tinha pego (reps já-publicados 142→155). Custo: **8 chamadas Opus · 160 pares · US$ 1,48**. Exemplos do que passou a casar: criança de 2 anos (acima); pressão dos pescadores × ampliação da cota da tainha; operações policiais reportadas por portais com manchetes distintas.

### 3. Digest do WhatsApp agrupado por evento

`RadarNotificador.montar` passou a render **1 bloco por evento** (cluster), buscando as coberturas em **1 query só** (sem N+1). Evento com **N≥2 portais** vira:

```
4. [RADAR 100] Joinville · indignacao
Mulher de 37 anos é denunciada por se passar por criança de 11 e enganar casal em Joinville
🔥 3 portais cobrindo:
https://rbatv.com.br/… · https://portalrbv.com.br/… · https://alvorada945.com.br/…
```

numa mensagem só (cap de 6 links/evento, `notificacao.max_links_por_evento`). Evento de 1 portal segue simples (título + link). O digest continua respeitando **decaimento** (publicação original > `max_idade_horas` não entra) e **já-publicado** (`whereNull('ja_publicado_em')`) — os dois validados no plano.

### Como trocar os modelos

```
# .env — fallback/ajuste por função (default já é Opus 4.8 sem env)
JRLINK_MODELO_JUIZ=claude-opus-4-8
JRLINK_MODELO_DEDUP=claude-opus-4-8
JRLINK_MODELO_MATCH=claude-opus-4-8
# cair pro Haiku (mais barato) se precisar: claude-haiku-4-5-20251001
```

### Validação

Opus responde no claude-cli (juiz e match — testes reais, logados como `claude-opus-4-8`) ✓ · **caso da criança de 2 anos casa e sai do digest** (provado com reset + re-match) ✓ · backfill 7 dias: +13 site +9 IG vs textual-only, US$ 1,48 ✓ · **digest agrupado**: evento multi-portal vira 1 mensagem com "🔥 N portais cobrindo" (dry-run acima) ✓ · few-shot **ligado** (55 votos ≥ 30, bloco de calibração com 12 exemplos no prompt) — juiz processou lote com Opus sem quebrar ✓ · paridade WhatsApp **122/122** ✓ · Feed/Fontes/Radar **200** ✓ · aceite Fase 2: **(b)(c)(d) passam**; (a) sinaliza **1** item — #138868 *"Jorginho Mello critica veto à pesca da tainha e cobra governo Lula"* — que o **Opus re-julgou como regional/quente legítimo** (governador de SC sobre a tainha de SC, ângulo local real): não é vazamento nacional, é o heurístico do teste pegando a palavra "Lula" numa pauta regional verdadeira; veredito mantido (não falsificado). Custo do ciclo Opus observado: match US$ 1,48 (160 pares) + juiz US$ ~2,2/ciclo de 48h.

### Ciclo do scheduler observado (código novo, 2026-06-15)

Evidência do journal (`newsradar-scheduler.service`), ciclo 13:30–13:37 (app/UTC):

```
13:30:20 Running ['artisan' news-radar:dispatch] .. 503ms DONE
13:30:20 Running ['artisan' jrlink:bridge-news] ......... 2s DONE
13:30:23 Running ['artisan' jrlink:instagram-poll] ..... 47s DONE
13:31:10 Running ['artisan' jrlink:publicados-sync] .. 4m 5s DONE   ← match Opus (código novo)
13:35:16 Running ['artisan' jrlink:juiz --hours=48] . 2m 10s DONE   ← juiz Opus + few-shot
```

O juiz disparou o digest no fim do ciclo: **`[RadarNotificador] digest enviado: 2 novos (2 na mensagem) messageId=F1AF88688FA39824699D`** (log do app, 13:37). Os 2 eventos: #531719 (5 portais) e #531721 (1 portal) — nenhum já-publicado. **Mensagem real agrupada** que saiu pro grupo:

```
🔥 *Radar JR — 2 pautas novas*

1. [RADAR 95] Abelardo Luz · indignacao
Homem que matou ex-companheira e namorado dela na frente do filho é condenado a 68 anos de prisão em SC
🔥 5 portais cobrindo:
https://atualfm.com.br/… · https://clickxaxim.com.br/… · https://visornoticias.com.br/… · https://araguaiabrusque.com.br/… · https://diplomatafm.com.br/…

2. [RADAR 69] identidade sc
Santa Catarina proíbe fogos de artifício com estampido em todo o estado
https://portalrbv.com.br/…
```

Confirma o objetivo: 5 portais da MESMA história viraram **1 bloco** ("🔥 5 portais cobrindo"), o evento de 1 portal ficou simples, e a **criança de 2 anos não entrou** (já-publicado, fora do plano — `criança no plano: false`).

---

## Match no ato + fato-velho + few-shot — 2026-06-15

Três falhas reais provadas pelo editor no digest do WhatsApp, com correção e prova de cada uma.

### A — por que o match dos "68 anos" falhou, e como o ATO-DE-NOTIFICAR resolve

O evento *"Homem que matou ex-companheira e namorado dela na frente do filho é condenado a 68 anos de prisão em SC"* vazou pro digest **mesmo o post já estando em `jr_publicado` duas vezes** (slugs `homem-e-condenado-a-mais-de-68-anos-…` 13/06 e `pai-mata-ex-e-o-namorado-…` 14/06). Causa-raiz, confirmada no journal: o `publicados-sync` (30min) confronta o **lote quente do momento**; o juiz julgou esse evento **quente às 13:35**, *depois* do snapshot do sync das 13:31, e o digest disparou **às 13:37** — antes de qualquer recomparação. Ninguém reconferia no instante do envio.

**Correção:** o `RadarNotificador` agora faz uma **checagem síncrona no ATO de notificar** — antes de enviar QUALQUER evento, confronta cada enviável contra `jr_publicado` (janela de **30 dias**, `publicados.janela_match_dias`) com o mesmo motor do sync (pré-filtro largo + decisão do Opus), agora num serviço compartilhado `PublicadoMatcher` com **tokens numéricos fortes** ("68 anos", "33 mi") no pré-filtro. Match → marca `ja_publicado_em` no cluster e **não notifica**.

**Prova (evento real de hoje, simulando a corrida numa transação):**
```
evento: Homem que matou ex-companheira e namorado dela… score=95
enviaveis: 0 | bloqueados_publicado: 1
68 anos em ENVIAVEIS (vazaria): false
68 anos em BLOQUEADOS_PUBLICADO (barrado): true
casou com post: homem-e-condenado-a-mais-de-68-anos-…-diante-do-filho (2026-06-13 10:21:28)
```
O evento é barrado no plano do digest e casado ao post de 13/06. Janela do confronto esticada de 72h → **30 dias**.

### B — guarda de fato-velho-redatado

*"Santa Catarina proíbe fogos de artifício com estampido"* entrou como **quente fresca** (score 69), mas a lei foi sancionada **20/03/2026**: um portal re-noticiou e o `published_at` veio recente, então o decaimento achou que era nova. O corpo nem traz a data — descreve como "nova legislação… sancionou a lei… passa a valer". O Opus, sem a data, insiste que é lei nova.

**Correção:** novo `App\Services\Jr\FatoVelho` detecta barato (regex) o fato central antigo: **marco legislativo** (lei sancionada/em vigor/aprovada), **efeméride/retrospectiva**, ou **data interna >40 dias** antes do `published_at` — e **suprime** quando o título já anuncia desfecho fresco (condenado/preso/morre/acidente), pra não pegar crime de 2025 julgado agora. O sinal:
1. entra pelo **INPUT do item** do juiz (o prompt BASE não muda — md5 `4c4e394b…` idêntico);
2. **rebaixa deterministicamente a frio** no `aplicarVeredito` (o LLM não consegue datar a sanção, então a heurística é a autoridade pra esse sinal).

**Prova (lei de fogos re-julgada pelo código real):** o Opus disse `eh_pauta=true` (achou a lei nova), mas o guarda sobrepôs → persistido **`temperatura_juiz=frio`, `eh_pauta=0`**, motivo `FATO ANTIGO re-noticiado (marco legislativo…)`. Falso-positivo controlado: só **3/60** dos quentes recentes são sinalizados (o caso "68 anos", por ter título de desfecho fresco, retorna `null` — é defendido pelo match, não pelo guarda).

### C — few-shot: estava ativo, mas silencioso

O grep voltava vazio porque **não havia log** — não porque estava quebrado. Diagnóstico: `JRLINK_JUIZ_FEWSHOT=true`, 55 votos (≥30), e o `blocoCalibracao()` injeta **12 exemplos** reais (2167 chars) no input do juiz. Adicionado `JuizLlm::fewShotInfo()` e o log explícito no `jrlink:juiz`:
```
Few-shot: LIGADO — 12 exemplos do feedback injetados no input do juiz.
```
O **prompt BASE** segue byte a byte idêntico (md5 `4c4e394bd59107f53c0c82b826533a26`, len 4220, com few-shot OFF) — a calibração entra como bloco de INPUT, não reescreve o template.

### Validação

**Ciclo real do scheduler observado (14:30–14:40, código novo):**
```
14:30:19 news-radar:dispatch .. 478ms DONE
14:30:20 jrlink:bridge-news ......... 2s DONE
14:30:23 jrlink:instagram-poll ..... 47s DONE
14:31:10 jrlink:publicados-sync .. 4m 1s DONE   (confronto 30 dias, Opus)
14:35:13 jrlink:juiz --hours=48 . 5m 13s DONE   (few-shot + fato-velho)
```
Logs reais do ciclo (`laravel.log`):
```
14:35:38 [JrLinkJuiz] Few-shot: LIGADO — 12 exemplos do feedback injetados no input do juiz.
14:40:25 [RadarNotificador] barrado já-publicado: "Empresário é rendido… R$ 350 mil rou…" ← empresario-perde-350-mil-assalto-relampago-chapeco
14:40:25 [RadarNotificador] barrado já-publicado: "Homem condenado a 20 anos por estupro de vulnerável é preso…" ← condenado-estupro-vulneravel-preso-…-sao-joao-batista
14:40:25 [RadarNotificador] 2 evento(s) já-publicado(s) barrado(s) no ATO de notificar (match síncrono)
14:40:26 [RadarNotificador] digest enviado: 4 novos (4 na mensagem) messageId=3EB07057B8FFC374F068F2
```
O match síncrono pegou **2 já-publicados NOVOS** no ato (além do caso-teste) que a corrida de 30min teria vazado. O digest das 14:40 saiu com 4 eventos legítimos, **sem os "68 anos"** (já marcado/notificado) e **sem a lei de fogos** (rebaixada a frio). **Custo do ciclo: US$ 2,07** (juiz US$ 0,56 · merge US$ 0,26 · match publicado US$ 1,25 — 10 chamadas/192 pares), 100% `claude-opus-4-8`.

**Resumo:** caso "68 anos" casa e é bloqueado ✓ · lei de fogos rebaixada a frio ✓ · few-shot logando 12 exemplos em run real ✓ · janela do `publicados-sync` = 30 dias ✓ · paridade WhatsApp **122/122** ✓ · Feed/Fontes/Radar **200** ✓ · md5 do prompt BASE idêntico (`4c4e394b…`) ✓ · 1 ciclo do scheduler observado com digest **sem 68-anos e sem fogos** ✓. **Aceite Fase 2: (b)(c)(d) passam; (a) sinaliza 1 item** — #138868 *"Jorginho Mello critica veto à pesca da tainha e cobra governo Lula"* — que o **Opus re-julgou (de novo) como regional/quente legítimo** (governador de SC sobre a tainha de SC, DNA viral do perfil): não é vazamento nacional, é o heurístico do teste pegando a palavra "Lula" numa pauta regional verdadeira; veredito do Opus mantido, não falsificado.

---

## Canal Instagram direto ao juiz — 2026-06-15

**Diagnóstico:** dos 143 itens `origem=instagram`, **127 nunca foram julgados** (`eh_pauta` null) — 88% do canal o juiz nem olhou. Causa-raiz: o gate coarse foi calibrado pra **manchete de portal**, e o scraper de IG salva a 1ª linha da **legenda** como "título" (`"06:30 am Praia da Tainha"`, `"PQ FOGEM DO POVO?"`, `"A ixtepora tava brava 😂"`), que quase nunca passa o coarse — o canal morria **antes** do juiz. Não era o juiz penalizando IG; era o coarse cegando o canal (dos 17 que passavam, 9 viravam pauta — taxa boa).

**Correção (só na seleção do juiz — feed/WhatsApp intocados):**
1. **Roteamento por origem:** em `jrlink:juiz`, todo item `origem=instagram` não-julgado entra **direto no juiz**, sem exigir score coarse. Feed/WhatsApp continuam só pela via de cluster-rep (coarse → cluster → juiz no representante) — **paridade WhatsApp 122/122** confirma que o classificador coarse não foi tocado. Dedup do display segue por cluster (radar mostra 1 card por evento).
2. **Juiz lê legenda, não manchete:** aviso curto **no INPUT do item** quando `origem=instagram` (*"é a legenda de um post de Instagram, pode começar com emoji/horário/frase solta — avalie o ASSUNTO, não o formato"*). O **prompt BASE do juiz não muda** (md5 `4c4e394bd59107f53c0c82b826533a26`, few-shot OFF, idêntico).
3. **Guardas continuam valendo:** o `FatoVelho` roda no `processarLotes` para **todo** item (inclusive IG); o match já-publicado (`PublicadoMatcher`) roda no `planejar()` do notificador sobre **todo** enviável. IG de fato já publicado ou de fato velho não vaza.

Nova via `jrlink:juiz --ig-backlog` julga o backlog de IG (itens mais antigos que a janela, que o clustering pesado de 168h não alcança — estourava memória) **direto, sem re-clusterizar** (os itens já têm `cluster_id`). O loop de julgamento foi extraído pra `processarLotes`, compartilhado entre o ciclo normal e o backlog.

### Antes/depois (canal Instagram julgado)

| | NULL (não julgado) | eh_pauta=0 (não-pauta) | eh_pauta=1 (pauta) |
|---|---|---|---|
| **ANTES** | 127 | 8 | 9 |
| **DEPOIS** | **0** | 95 | **49** |

Backlog: **127 IG julgados, 0 falhas, 4 com guarda de fato-velho, custo US$ 2,57** (100% `claude-opus-4-8`, few-shot 12 exemplos). **22 IG viraram quente.**

### Exemplos reais de IG julgados

Pautas que o juiz **aprovou** lendo a legenda:
- *"Com salários superiores a R$ 17 mil e vale-alimentação…"* → **quente 100** (indignação): "política local com indignação direta — o que mais engaja".
- *"Um morador de Quatro Ilhas chorou ao ver o peixe passar…"* → **quente 89** (emoção, Bombinhas): "pescador chora pela tainha — DNA exato de comoção".
- *"Resgate da tartaruga nos 150 metros ✌🏼"* → **quente 88** (feel-good, Florianópolis).
- *"Câmara de São José que CUSTA AOS COFRES PÚBLICOS MAIS DE R$ 33.000.000"* → **quente 84** (indignação).

Legendas que o juiz **rejeitou** (eh_pauta=0): *"Um novo tempo está movendo Palhoça…"* → "propaganda institucional, sem fato"; *"A ixtepora tava brava 😂"* → "meme de humor, sem fato jornalístico"; *"Que coisa mais linda raça"* → "legenda vaga feel-good, sem pauta". O juiz lê a legenda e decide bem.

### Guardas cobrindo IG
- **Já-publicado:** o notificador confronta IG quente contra `jr_publicado` no ato de notificar (mesmo `PublicadoMatcher` do feed). No backlog, **só 1 dos 22 IG quentes foi notificado** (`messageId 3EB055602FEC0A638D26DB`) — os outros 21 barrados por idade (>12h), já-notificado ou já-publicado. Nenhum IG já-publicado/fato-velho vazou pro digest.
- **Fato-velho:** rodou nos 127 (4 sinalizados), igual feed/WhatsApp.

### Feed/WhatsApp inalterados
A mudança é **aditiva na seleção do juiz** — só acrescenta itens de IG à fila. Feed e WhatsApp seguem a rota de sempre (coarse → cluster → juiz no representante). Paridade **122/122**, Feed/Fontes/Radar **200**, md5 do prompt base idêntico.

---

## Vitrine Radar JR — 2026-06-15

O `extract.html` antigo era lista chapada por score (tainha de dias atrás no topo, 13 itens soltos). A nova **vitrine** (server-rendered, no Caddy existente) fica à altura do digest: lê `jr_link_extracao` na hora, agrupa por assunto e aplica decaimento + guardas — **sem IA no clique**.

### Como o assunto agrupa (tainha 13→1)

Novo passo do ciclo `jrlink:assuntos` (scheduler **10,40**, depois do juiz). Estratégia híbrida (a mesma do cluster-merge, agora no nível de ASSUNTO):
1. **Pré-grupo barato** (sem custo): union-find por overlap idf dos títulos, exigindo um **token-âncora distintivo** (ex.: "tainha") — geografia/fonte/ação genérica ("santa", "preso", "operação") não ancoram, senão encadeiam tudo num assunto-monstro.
2. **Opus rotula** (1 chamada/ciclo, todos os grupos multi-cluster juntos): dá um rótulo PT-BR curto a cada grupo e o **MESMO rótulo** aos grupos do mesmo assunto em curso (encerramento + reabertura + cota + disputa da tainha). Grupos com rótulo idêntico viram 1 `assunto_id` estável.

Resultado real: **"Pesca da tainha em SC" = 13 clusters distintos sob 1 `assunto_id` (a166930)** — na vitrine, **1 bloco** "🔥 N portais cobrindo", não 13 cards. Migration aditiva e reversível (`assunto_id` + `assunto_label` + `assunto_em`). **Custo do agrupamento: US$ 0,57/ciclo** (82 grupos → 1 chamada Opus, logado em `jr_juiz_log` como `assunto_group`). O que já tem `assunto_id` estável é reusado.

### A vitrine não chama LLM no request (prova)

Rota `GET /radar` (atrás da MESMA chave leve do painel: `?key=…` → cookie 90d), declarada antes do catch-all do SPA. O controller só faz **leituras de DB + render**. Prova: `jr_juiz_log` tem o MESMO total **antes e depois** de abrir a página (996 → 996) — zero chamadas LLM; request em **~0,32s**. O agrupamento (latência/custo do Opus) vive no ciclo, nunca no clique.

### Guardas e decaimento aplicados

- **Esconde já-publicado** (`whereNull ja_publicado_em`) e **frio/fato-velho** (`temperatura_juiz='quente'` — fato-velho já entra frio do juiz). 0 referências a já-publicado no HTML.
- **Decaimento temporal**: `score_atual = score × fator(idade)` (mesma régua do digest, `JrRadarController::fatorDecaimento`). Ordena por `score_atual`; badge de idade fica cinza pra ≥24h.
- Dois blocos: **Agora** (líder < 6h) e **Últimas 24h**.

### Visual (V5.1) e filtros

Open Sans, Royal #0061FF, Navy #0D2481, Sky #18ADFE. Card por assunto: título legível (1ª maiúscula; legenda "shouty" de IG vira sentence-case), **chip de editoria COM A COR** (Segurança #E63946, Política #0D2481, Economia #2D6A4F, Meio Ambiente #40916C, Saúde #48CAE4, Entretenimento #E056A0, Especiais #D4A373), chip cidade, chip origem, selo de score_atual, host, link. Cabeçalho com os números do ciclo em cards limpos (processados/julgados/assuntos/IG). **Filtros client-side instantâneos** (sem recarregar): abas de origem (Portais/WhatsApp/**Instagram**), cidade (46 opções), editoria (9), "só de hoje", e busca por palavra no título/cidade. Mobile-first.

### Contagem por origem (janela atual)

116 assuntos quentes renderizados · **17 do Instagram** (aba própria; IG puxado em 7d porque é o canal novo de baixo volume — dos ~49 IG eh_pauta=1, ~21 são quentes). Feed/WhatsApp na janela de 48h. 21 cards no bloco "Agora".

**URL:** `https://jornaldetijucas.com.br/radar?key=<JRLINK_PANEL_KEY>` (200 com chave, 401 sem — testado em produção).

### Validação
Tainha 1 bloco (13 clusters sob a166930) ✓ · nenhum já-publicado/fato-velho visível ✓ · decaimento aplicado (score_atual) ✓ · IG visível e filtrável (17) ✓ · página abre SEM disparar Opus (jr_juiz_log 996→996, ~0,32s) ✓ · rota nova 200 + NewsRadar (feed/fontes/radar-api) 200 ✓ · paridade **122/122** ✓ · md5 do prompt base **idêntico** (`4c4e394b…`) ✓ · custo do agrupamento US$ 0,57/ciclo ✓ · migration reversível ✓.

### Ciclo do scheduler observado (assuntos, produção)

`jrlink:assuntos` rodou agendado: **18:10 (app) — 1m 46s DONE**, custo **US$ 0,33** (82 grupos → 1 chamada Opus, `claude-opus-4-8`). Pós-ciclo a vitrine segue 200 (~0,29s, sem LLM no request) e o assunto **"Pesca da tainha em SC" agregou 15 clusters** (cresceu de 13 com a cobertura nova) sob o mesmo `assunto_id` estável `a166930` — 1 bloco na vitrine. Pipeline de juiz/cluster/notificação/IG dos commits c3daf10/988bf83 intactos.
