---
name: podcast-cortes
description: >-
  Pega um episódio de podcast já editado (horizontal) do Jornal Razão e gera
  vários cortes verticais 9:16 (1080x1920), um por bloco/tema, com legenda
  queimada, prontos para TikTok/Instagram/Shorts. Use quando o usuário pedir
  "cortes", "clipes", "versão vertical", "shorts" ou distribuição em redes.
  Usa a skill video-use por baixo.
---

# Skill: Cortes Verticais de Podcast (Jornal Razão)

Transforma o episódio horizontal em **vários clipes verticais curtos**, um por
assunto, para redes. Padrão da redação já definido — poucas perguntas.

> Motor: **video-use** (`~/.claude/skills/video-use`).
> Rode normalmente **depois** da skill `podcast-edicao`.
> Guia completo: `docs/PADRAO-EDICAO-PODCAST.md`.

## Entrada

- Um episódio **já editado** (ex.: `.../edit/final.mp4`) **ou** o bruto + pedido
  de editar antes (nesse caso, rode `podcast-edicao` primeiro).
- A transcrição já existente em `edit/` (reaproveite — **não re-transcreva**).

## Como escolher os cortes

1. Use a transcrição para detectar **blocos/temas** (cada notícia, pergunta ou
   assunto vira um candidato a corte).
2. Cada corte deve ser **autossuficiente**: começa com um gancho e fecha a ideia.
3. **Duração padrão: 60–90s** (aceitável 30–120s). Se um tema for longo, pegue o
   trecho mais forte.
4. Corte **em limite de palavra** e comece/termine em respiro natural.
5. Se o usuário não disser quantos, gere **um corte por bloco** e liste todos.

## Formato de saída (padrão redes)

- **Vertical 9:16 → 1080×1920 @ 30fps.**
- **Reenquadramento**: mantenha o rosto/locutor centralizado no quadro vertical
  (crop centrado no falante; se houver tela, priorize o rosto ou empilhe
  rosto em cima / tela embaixo quando a tela for essencial ao trecho).
- **Legenda queimada** (padrão social):
  - estilo `bold-overlay`: blocos de ~2 palavras, MAIÚSCULAS, fonte bold, branco
    com contorno — legível no mudo. Ajuste tamanho para não cobrir o rosto.
- **Fades** de 30ms em cada emenda (a video-use já aplica).
- **Cor**: herda do episódio; não re-graduar salvo pedido.

## Fluxo

1. Localize o `final.mp4` e a transcrição em `edit/`.
2. Proponha a **lista de cortes** (título/tema, timecodes, duração estimada) —
   4–8 linhas. **Espere confirmação** antes de renderizar em massa.
3. Renderize cada corte para `edit/cortes/corte-NN-<slug-do-tema>.mp4`.
4. Auto-avalie amostras (início/fim/meio) de cada corte: legenda alinhada,
   rosto no quadro, sem estouro de áudio. Corrija e re-renderize (até 3 passadas).
5. Entregue a **lista final** com caminho, tema e duração de cada corte.

## Saídas

```
<pasta>/edit/cortes/
├── corte-01-<tema>.mp4
├── corte-02-<tema>.mp4
└── ...
```

Opcional: gere um `cortes/index.md` com tema, duração e uma legenda/caption
sugerida por corte (útil para agendar nas redes).

## Publicação (se solicitado)

Se houver integração com TikTok/Instagram configurada, ofereça subir como
**rascunho**. Caso contrário, entregue os arquivos + captions para upload manual.

## Não faça

- Não re-transcrever (reutilize a transcrição do episódio).
- Não gerar corte que corta a fala no meio de uma frase importante.
- Não queimar legenda que cobre o rosto — ajuste posição/tamanho.
- Não publicar direto sem confirmar (sempre rascunho, salvo pedido explícito).
