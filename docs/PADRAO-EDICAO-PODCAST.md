# Padrão de Edição de Podcast com IA — Jornal Razão

> Fluxo padrão da redação para editar podcasts e gerar cortes usando um agente de IA
> (Claude Code, Codex etc.). Inspirado no processo do canal **Ratos de IA**
> ("PAREI de editar o vídeo na mão: agora o Claude Code edita tudo").
>
> **Ideia central:** você joga o arquivo bruto pro agente e ele faz a edição
> (corta erros, silêncios, ajusta layout), depois transforma em cortes verticais
> pra TikTok/Instagram. Nada de editar frame a frame na mão.

---

## 1. Visão geral do fluxo

```
  Gravação bruta            Edição (agente)             Distribuição
 ┌───────────────┐        ┌──────────────────┐        ┌──────────────────┐
 │ rosto + tela  │        │ 1. transcreve    │        │ episódio final    │
 │ (ou só áudio) │  ───▶  │ 2. corta erros   │  ───▶  │ .mp4 horizontal   │
 │  + palavras-  │        │ 3. corta silêncio│        │  +               │
 │    chave      │        │ 4. ajusta layout │        │ cortes verticais  │
 └───────────────┘        └──────────────────┘        │ (9:16) p/ redes   │
      skill: —              skill: podcast-edicao      └──────────────────┘
                                                        skill: podcast-cortes
```

Duas skills padronizam o trabalho (ver `.claude/skills/`):

| Skill | O que faz | Quando usar |
|-------|-----------|-------------|
| **`podcast-edicao`** | Pega o(s) arquivo(s) bruto(s) e devolve o episódio horizontal limpo: sem silêncios, sem erros, com o layout certo. | Sempre, logo após gravar. |
| **`podcast-cortes`** | Pega o episódio editado e quebra em vários clipes verticais (9:16), um por bloco/tema, prontos pra redes. | Depois da edição, para divulgar. |

Ambas usam por baixo a ferramenta **`video-use`** (open source, multiplataforma).
No Mac também dá pra usar o **Palmier** (editor visual). Ver seção 6.

---

## 2. Ferramentas do padrão

| Ferramenta | Papel | Plataforma | Custo |
|-----------|-------|-----------|-------|
| **[video-use](https://github.com/browser-use/video-use)** | Motor de edição via agente (skill). **Base do padrão.** | Win / Linux / Mac | Grátis (skill) |
| **[Palmier Pro](https://www.palmier.io/)** | Editor visual + MCP; dá pra ver a edição acontecendo. | **Só Mac** (Apple Silicon) | Grátis (editor/MCP) |
| **tela** | Grava tela + câmera ao mesmo tempo (já entrega o layout). | Mac | Assinatura |
| **[Descript](https://www.descript.com/)** | Alternativa: edição de podcast por texto (MCP conectado nesta sessão). | Web / Mac / Win | Freemium |
| **[HyperFrames](https://github.com/) / Remotion** | Animações, legendas elaboradas, efeitos (opcional). | Multiplataforma | Grátis |

**Recomendação da redação:** padronizar em **`video-use`** (roda em qualquer máquina).
Quem tiver Mac pode usar o Palmier por cima quando quiser acompanhar visualmente.

---

## 3. Convenções de gravação (fazem a edição ficar boa)

O agente não "assiste" o vídeo — ele **lê a transcrição** e corta com precisão de
palavra. Então, quanto mais consistente a gravação, melhor o corte automático.

### 3.1 Palavras-chave de erro (padrão Ratos de IA)

Fale a palavra-chave em voz alta na hora que errar. O agente acha na transcrição
e corta sozinho:

| Palavra-chave | Significado | O que o agente faz |
|--------------|-------------|--------------------|
| **"pato amarelo"** | Erro curto | Volta um pouco, corta o trecho errado, mantém a repetição seguinte. |
| **"pato vermelho"** | Erro grande | Descarta a frase/parágrafo inteiro e mantém o recomeço. |

> Escolha palavras que **você nunca usaria** no conteúdo real. "pato amarelo/vermelho"
> funciona bem porque não aparece numa pauta de jornal. Mantenha o mesmo par sempre.

### 3.2 Boas práticas

- **Pausa curta antes de recomeçar** depois de um erro (facilita achar o corte).
- **Não fale por cima** de outra pessoa se der (facilita o corte por locutor).
- Se grava **rosto + tela** separados, entregue os **dois arquivos brutos**.
- Diga em uma frase, no começo, **o roteiro/pauta** (blocos e temas) — ajuda o
  agente a decidir layout e a fatiar os cortes por assunto.

---

## 4. Setup (uma vez por máquina)

Pré-requisitos: `git`, `ffmpeg`, `uv` (ou `pip`) e uma chave da **ElevenLabs**
(transcrição) — ou usar **Whisper local** (grátis, sem chave).

```bash
# 1. rode o instalador do padrão (clona e liga a video-use ao agente)
bash scripts/setup-video-use.sh

# 2. transcrição — escolha UMA:
#    (a) ElevenLabs Scribe (melhor qualidade, ~US$0,40/hora de áudio)
export ELEVENLABS_API_KEY="sua-chave"     # ou edite ~/Developer/video-use/.env
#    (b) Whisper local (grátis) — peça ao agente:
#        "use whisper local em vez da ElevenLabs para a transcrição"
```

Detalhes e verificação em `scripts/setup-video-use.sh` e na seção 6.

---

## 5. Como editar um episódio (passo a passo)

### Passo 1 — Editar o episódio (horizontal)

1. Coloque o(s) arquivo(s) bruto(s) numa pasta, ex.: `podcasts/ep-07/bruto/`.
2. Abra o agente **nessa pasta** (Claude Code / Codex).
3. Peça, referenciando a skill:

   > Use a skill **podcast-edicao**. Aqui estão os arquivos brutos do episódio 07
   > (rosto e tela). Corte silêncios e erros (palavras-chave pato amarelo/vermelho),
   > ajuste o layout conforme a skill e me entregue o `final.mp4`.

4. O agente transcreve → propõe um plano → **você confirma** → ele renderiza e
   se auto-avalia. Saída: `podcasts/ep-07/bruto/edit/final.mp4`.

### Passo 2 — Gerar os cortes (vertical, 9:16)

Com o episódio pronto:

   > Agora use a skill **podcast-cortes** no `final.mp4`: quebre em clipes verticais
   > de 60–90s, um por tema, com legenda queimada, prontos pra TikTok/Instagram.

Saída: `.../edit/cortes/corte-01.mp4`, `corte-02.mp4`, …

### Passo 3 — Publicar (opcional)

Se tiver conexão com as redes configurada, peça: *"suba os cortes como rascunho
no TikTok/Instagram"*. Caso contrário, os arquivos ficam prontos pra upload manual.

---

## 6. Instalação detalhada e alternativas

### 6.1 video-use (base do padrão)

```bash
git clone https://github.com/browser-use/video-use ~/Developer/video-use
ln -sfn ~/Developer/video-use ~/.claude/skills/video-use   # Codex: ~/.codex/skills/
cd ~/Developer/video-use && uv sync            # ou: pip install -e .
# ffmpeg é obrigatório:  Linux: apt install ffmpeg | Mac: brew install ffmpeg
cp .env.example .env                            # coloque ELEVENLABS_API_KEY
```

Regras que a video-use já garante (não precisa se preocupar): corte sempre em
limite de palavra, fades de 30ms em cada emenda, legenda aplicada por último,
transcrição cacheada (não re-transcreve à toa) e auto-avaliação de cada corte
antes de te mostrar. Saídas sempre em `<pasta>/edit/`.

### 6.2 Palmier (opção Mac, editor visual)

1. Baixe e instale o Palmier Pro (macOS Apple Silicon) em <https://www.palmier.io/>.
2. Abra o app e crie o projeto (ele precisa ficar aberto).
3. Conecte o MCP: menu **Help → MCP Instructions → instalar no Claude Code**
   (o app expõe um MCP local em `http://127.0.0.1:19789/mcp`).
4. Peça a edição normalmente — a diferença é que você **vê os cortes acontecendo**
   na timeline em tempo real.

### 6.3 Descript (alternativa por texto)

O MCP do Descript está conectado nesta sessão. Serve bem para podcast em áudio ou
vídeo: importa a mídia, transcreve e você edita "apagando texto". Bom para quem
prefere revisar a transcrição antes de cortar. Não substitui o padrão — é opção.

### 6.4 Animações/efeitos (opcional)

Para legendas animadas e efeitos, peça ao agente para usar **HyperFrames** ou
**Remotion** por cima do corte já feito. Não é necessário para o padrão minimalista.

---

## 7. Organização de pastas (sugerida)

```
podcasts/
└── ep-07/
    ├── bruto/                 ← arquivos originais (rosto.mp4, tela.mp4 ou audio.wav)
    │   └── edit/              ← gerado pela video-use
    │       ├── final.mp4      ← episódio editado (horizontal)
    │       ├── master.srt     ← legenda
    │       ├── takes_packed.md← transcrição (leitura do agente)
    │       └── cortes/        ← clipes verticais (9:16)
    └── pauta.md               ← blocos/temas do episódio (ajuda o agente)
```

---

## 8. Checklist rápido

- [ ] Gravei falando "pato amarelo/vermelho" nos erros.
- [ ] Coloquei os brutos em `podcasts/ep-XX/bruto/`.
- [ ] Abri o agente na pasta e chamei a skill **podcast-edicao**.
- [ ] Confirmei o plano e recebi o `final.mp4`.
- [ ] Chamei a skill **podcast-cortes** para gerar os verticais.
- [ ] Revisei 2–3 cortes e publiquei/agendei.

---

*Documento vivo. Ajuste as convenções conforme a redação evolui o processo.*
