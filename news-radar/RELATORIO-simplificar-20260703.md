# 📋 RELATÓRIO — GOAL SIMPLIFICAR · 03/07/2026 (tarde)

> Feedback do dono (03/07 14h): *"o hub unificado ficou uma bagunça; a aba notícias não abre no
> celular; os grupos estão cheios de cidade irrelevante."* Goal de REFINO — zero feature nova
> fora da régua. Baseline: `b03cbb2`. Sessão dentro das 3h; texto em lote 100% OpenAI.

## 🚦 Semáforo

| Bloco | Estado | Evidência |
|---|---|---|
| 1 · Separar páginas | 🟢 | 6 páginas 200, todas <300KB, zero iframe; screenshots 390px |
| 2 · Anti-garbage | 🟢 | ANTES 110 → DEPOIS 38 (**corte 65,5%**), núcleo preservado |
| 3 · Grupos por fonte | 🟢 | 3 grupos roteados + estreia com messageId em cada |
| 4 · Já-publicado | 🟢 | causa real (cURL 28 sem retry + flag ignorada) + 3 provas |
| 5 · Bug agregador | 🟢 | reproduzido + causa + fix triplo + digest limpo (messageId) |
| 6 · Publicar 1-clique | 🟡 | draft WP 199071 completo; Mesa síncrona BLOQUEADA (env stale) |
| 7 · Rascunho via GPT | 🟢 | driver no ar (gpt-5.5) + A/B 3 pares colado + default GPT |
| 8 · Fecho | 🟢 | watchdog 13 checks (2 novos) + 3 céticos + este relatório |

---

## BLOCO 1 — páginas separadas (hub desfeito)

O hub era **2,1MB** com 2 iframes (a "aba que não abre no celular"). Agora:

| Página | Tamanho | Antes |
|---|---|---|
| `/radar-civico` (índice: 5 cartões + contador + "último há X min" + /mesa) | **3KB** | 2.138KB |
| `/dom` | 242KB | — |
| `/camaras` | 256KB | — |
| `/justica` (MPSC+TCE, filtro por fonte) | 273KB | — |
| `/prefeituras` | 171KB | — |
| `/radar` (vitrine standalone DE VOLTA) | 187KB | iframe |

Zero iframe servido; redirects 308 (`?fonte=`→página, `?painel=noticias`→/radar, `?painel=mesa`→/mesa,
`/dom-radar`→/dom, oportunidades.html→/dom); deep-link antigo `#ato-…` reencaminha; alertas novos já
apontam pra página da fonte. Como coube em <300KB: corte final por página (fresco sobrevive primeiro)
+ campos LLM aparados no JSON (íntegra continua no botão lazy). Screenshots 390px:
`~/backups/simplificar-20260703/screens/`.

## BLOCO 2 — anti-garbage (o pedido central do dono)

**Auditoria ANTES** (110 últimos envios reais das 4 mesas — `auditoria-antes.json`):
- 22 itens (20%) de **cidade fora do interesse** (Araranguá, São Bento do Sul, Laguna, "Saúde da AMREC"…)
- 47 itens (43%) com score < 80 · 5 repetições · 1 objeto vago

**Regras duras POR GRUPO** (`GrupoFiltro`, config `radar_civico.grupo_filtro`, 6 linhas env-ajustáveis):

```
tier0 / objeto vago / objeto rotineiro (show por inexigibilidade, licença de
software, termo aditivo)                          → NUNCA em grupo
núcleo (RADAR_CIVICO_CIDADES, 6 cidades)          → score ≥ 70
fiscalização                                      → tier1 ≥ 80 · tier2 ≥ 85
serviço fora do núcleo                            → só site (auto-rascunho já entrega os melhores)
notícia (quente-fria)                             → núcleo ≥ 70 · tier1 ≥ 80 · tier2 ≥ 85
repetição (cidade+objeto)                         → dedup no alertar
```

**DEPOIS (simulado na MESMA janela): 110 → 38 = corte de 65,5%** (meta ≥60% ✓), mantendo TODOS os
itens que o editor da região quereria (conferência região Tijucas no `auditoria-depois.json`: MP/estiagem 82,
licenças ambientais 88/85, Justiça Federal/Estrada 74, revitalização 75 — todos DENTRO; só rotina 60-68 fora).
Barrado ≠ descartado: continua no site/Mesa com score intacto.

## BLOCO 3 — grupos separados por fonte

Os 3 grupos novos JÁ enxergavam a instância de alerta às 18h. Roteamento config-driven
(`radar_civico.canais.civico` + `canais.noticias`):

| Rota | Grupo | Estreia (messageId) |
|---|---|---|
| DOM + câmaras | Raspador Diário Oficial | `3EB0D2CA52B81FE624D29F` |
| MPSC + TCE | Raspador Justiça (MP + TC) | `3EB02FF3268943F2C34201` |
| Releases de prefeitura | Raspador Cidades | `3EB066796EDD491F6D1B30` |
| Notícias de portais (quente-fria + notificador) | Raspador (atual) | digest bloco 5 |
| Auto-rascunho / kit / fluxo ✅ | Rascunhos (SÓ isso) | — |

Digest cívico agora é **1 por grupo**; N3 roteado pela fonte; `--seed` rodado antes (backlog 0);
falha num grupo não registra o lote (re-tenta). **Decisão listada**: quente-fria foi pro grupo
Raspador junto do digest do notificador (mapeamento do goal "notícias → Raspador atual") — e o eco
entre os dois digests foi fechado no bloco 4 (`whereNull(notificado_em)`).

## BLOCO 4 — já-publicado nunca no topo nem em grupo

**Causa real do vazamento (dupla):**
1. `publicados-sync` morria INTEIRO com `cURL error 28` (connect timeout, sem retry) — run de 07:31
   falhou e nada foi flagado o dia todo (log colado no commit).
2. `quente-fria` **não filtrava a flag**: itens 2909064/2882577 estavam `ja_publicado_em` setado e
   foram enviados mesmo assim.

**Fixes:** retry+catch no sync (falha vira warning, mantém parcial) · `whereNull(ja_publicado_em)`
+ `whereNull(notificado_em)` no quente-fria · **`PublicadoMatcher::casaBarato`** (novo, SEM LLM:
URL canônica + título normalizado + fuzzy ≥72%, corpus 30d em cache 30min) como rede síncrona na
vitrine, notificador (antes do LLM — segura o fail-open), alertar (release já coberto) e kit
(república com slug novo ≠ 2º kit).

**Prova (3 matérias publicadas hoje):** "pastor/igreja condenada" · "adolescente internado Chapecó" ·
"nortista quarto R$800" — (i) nenhuma no topo da vitrine (grep no HTML); (ii) dry-run quente-fria:
32 classificadas → **0 quentes**; dry-run alertar: 0.

## BLOCO 5 — bug do "agregador de links"

**Reproduzido com dados reais**: cluster 2117434 — a linha "🔥 6 portais cobrindo" misturava o link
de *"Pesquisa Atlas: Lula testado"* no meio da pauta *"Michelle deixa PL Mulher"*; e o ClusterMergeLLM
fundia o briefing *"QUARTA, 1/7: Michelle…"* (página multi-notícia) **a cada ciclo** (log 17:05/17:35/18:05).

**Causa:** over-merge de cluster (LLM + remap em cadeia) × template que imprimia TODOS os membros.
**Fix triplo:** guarda de coerência 0.4 no `coberturasPorEvento` (link perdido > link errado) ·
veto de briefing no merge LLM · fim do remap em cadeia (1 fusão por cluster por ciclo).
**Prova:** mesmo cluster re-renderizado limpo e enviado — messageId `FCD66F4CF6CD8492184C`.

## BLOCO 6 — publicar ponta-a-ponta

E2E real (sem publicar): ✅ simulado no webhook → **draft WP 199071**: status=draft ✓ título ✓
linha-fina ✓ corpo em parágrafos ✓ lacunas em comentário ✓ **tag de cidade** (novo) ✓.
Aditivos de hoje: tags no WP (`tagIds`, cidade sempre vira tag — controle NÃO tem categoria por
cidade, conferido via API) + crédito da foto no corpo do draft cívico.

**Checklist do que o Lorran ainda toca no admin (1-clique real):**
1. **Categoria** — cívico nasce "geral" (mapear palavra-chave→editoria é pendência sugerida).
2. Revisar lacunas do comentário HTML e apagar.
3. Botão Publicar.

**Pendências técnicas listadas** (não implementadas hoje): revisor pós-post e juiz visual não cobrem
drafts do ✅-cívico (selecionam só de `jr_pauta_publicacoes`); corpo é texto plano (sem links/intertítulos).

**🔴 BLOQUEADO (Trava #0):** Mesa→rascunho **síncrono** falha porque o `newsradar-web` foi iniciado
antes da chave OpenAI real (env congelado no processo; erro `sk-noop` em `jr_juiz_log` 18:46).
**Lorran, 1 linha:** `sudo systemctl restart newsradar-web` (o fluxo ✅→draft e todos os crons NÃO
dependem disso — rodam em processo fresco).

## BLOCO 7 — rascunho via GPT

- Driver `JRLINK_RASCUNHO_DRIVER=openai|claude` no ar (seam `jrlink.drivers`, 4 operações de geração);
  modelo por operação `JRLINK_RASCUNHO_MODELO` (default **gpt-5.5** — o melhor da chave, sondado em
  `/v1/models`; juiz continua no mini, **prompt/score intocados**).
- **A/B real** (3 itens: mpsc:2531 MP/estiagem, prefeitura:69 CEJA, dom:34696 Câmara SJB), MESMO
  prompt, claude-opus-4-8 vs gpt-5.5 — pares completos em `~/backups/simplificar-20260703/ab-rascunho.json`.
  Leitura: paridade de qualidade; GPT mais rigoroso na atribuição ("Segundo o ato publicado no
  DOM/SC"), Claude título um tico mais concreto. Nada enviado a grupo.
- **Default documentado: GPT** (regra do goal: igual/melhor → GPT; bate com a avaliação do dono).
- Bônus: `jr_juiz_log.model` e `jr_pauta_reescrita.modelo` paravam de MENTIR (gravavam mini/opus fixo).

## BLOCO 8 — fecho

- **Watchdog**: 2 checks novos — *Páginas separadas* (6/6 200, 0 acima de 300KB) e *Grupos por fonte*
  (4/4 rotas configuradas + último envio) — em health.html + WATCHDOG-estado.md.
- **Custo**: texto em lote 100% OpenAI (A/B usou claude-cli em 3 chamadas pontuais, mandato explícito
  do goal); juiz gpt-5.4-mini intocado.

## 🔍 Crítico final (3 céticos) — achados

Céticos: travas/segredos · "abre rápido no celular?" · "Lorran recebe menos e melhor?".
Travas do goal: **todas respeitadas com evidência** (nenhum serviço reiniciado; WP 100% draft;
instância única de alerta; zero segredo no diff; nada apagado; zero migrations).

**CORRIGIDOS na hora (P0 + P1):**
1. **P0** — `whereNull(notificado_em)` do bloco 4 deixou o quente-fria sem estoque (o notificador
   às :05/:35 marca TODO quente ≥60 antes) → o GrupoFiltro de notícia nunca rodava e **o caminho
   VIVO (RadarNotificador) seguia sem filtro** — tier0 (Treze Tílias, Matos Costa, São Bento) chegou
   ao grupo horas DEPOIS do deploy. **Fix**: GrupoFiltro::noticia agora roda dentro do
   `RadarNotificador::planejar` (reprovado = marcado, segue no site/Mesa, motivo logado); quente-fria
   assume o papel de triagem do painel.
2. **P1** — `termo aditivo` barrava fiscalização REAL do núcleo (6º aditivo do SAMAE de Tijucas,
   score 75, gancho "merece apuração"). **Fix**: rotina só se aplica FORA do núcleo (no núcleo o
   piso 70 decide).
3. **P1** — fuzzy do casaBarato casou 2 falsos positivos em 72h (acidentes/prisões distintos com
   tokens genéricos) e gravava `ja_publicado_em` permanente sem LLM. **Fix**: fuzzy conservador
   (≥4 tokens E ≥60% pelo lado MAIOR) + **todo match logado** (`[casaBarato]` no laravel.log) —
   reescrita profunda fica com o LLM, como sempre foi.
4. **P1** — vitrine /radar era beco sem saída no celular. **Fix**: "‹ Radar Cívico" + "📌 Mesa" no header.
5. **P1** — N3 cívico sem guarda de grupo vazio (fallback silencioso do ZapRascunhos cairia no grupo
   Rascunhos). **Fix**: mesma guarda do digest. + **P2** IDs de grupo mascarados no console/journal.

**LISTADOS (P2, não bloqueiam):**
- Dedup de repetição do alertar vale dentro do ciclo (cross-ciclo exigiria guardar objeto em
  jr_civico_alertas — aditivo futuro).
- Páginas são client-rendered a partir de JSON embutido (corpo vazio sem JS); /justica a 91% do teto
  de 300KB — margem apertada se os campos crescerem.
- Deep-link antigo `#ato-` pro índice depende de JS (fragmento nunca chega ao servidor — sem solução
  server-side); jumpHash silencioso se o item saiu do corte de 180.
- `/radar` responde em ~1,3s (5-6× as irmãs) — profiling de query fica pra depois.
- N3 do quente-fria com canal vazio consome vaga do teto diário sem enviar (raro; só sob misconfig).
- `?cidades=interesse` no índice degrada pro índice sem filtro (nenhum link do repo gera isso).

## 🅿️ PARQUEADO — RADAR TOTAL (retomada futura, 1 linha cada)

- **B1 CIGA/DOM full-text**: ingestão da íntegra dos PDFs do DOM via CIGA — retomar pela fila de scoring.
- **B2 TJSC releases**: raspar sala de imprensa do TJSC (ALESC bloqueia IP do VPS — TJSC não testado).
- **B3 MPSC releases**: notícias oficiais do MPSC como fonte 🟢 própria.
- **B4 Agenda pública**: agendas de prefeitos/câmaras como sinal de pauta futura.
- **B6 GA4 shadow**: score de audiência sombreando o score editorial (clique×retenção já analisado).
- **B7 Painel de custo**: consolidar custo_usd por operação num painel (jr_juiz_log já tem os dados).
- **B8 Story tracking**: acompanhar desdobramento de pauta (matéria→follow-up) por cluster.
- **B9 DOC-SYNC**: sincronizar docs/ com o estado real dos comandos (drift acumulando).
- **B10 Alertas nível-2 por e-mail**: digest diário por e-mail além do WhatsApp.

## Pendências do Lorran

1. `sudo systemctl restart newsradar-web` (desbloqueia Mesa→rascunho síncrono; 10 segundos).
2. Conferir os 3 grupos novos no WhatsApp (estreias entregues) e avisar se quiser thresholds diferentes
   (`RADAR_GRUPO_*` no .env, sem deploy).
3. Draft 199071 no admin do controle — exemplo do 1-clique (só categoria + Publicar).
