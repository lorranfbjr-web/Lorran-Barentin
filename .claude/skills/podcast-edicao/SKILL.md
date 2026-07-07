---
name: podcast-edicao
description: >-
  Edita um podcast/vídeo bruto do Jornal Razão e devolve o episódio horizontal
  limpo (final.mp4): remove silêncios, remove erros marcados com palavras-chave
  (pato amarelo/pato vermelho), ajusta o layout entre rosto inteiro e rosto+tela.
  Use quando o usuário mandar arquivo(s) bruto(s) de podcast/vídeo pedindo para
  cortar, limpar ou "editar o episódio". Usa a skill video-use por baixo.
---

# Skill: Edição de Podcast (Jornal Razão)

Padroniza a edição do episódio **horizontal**. Objetivo: episódio limpo, com corte
de erros/silêncios e o layout correto, com o mínimo de perguntas — o padrão da
redação já está definido aqui.

> Motor: **video-use** (`~/.claude/skills/video-use`). Esta skill é o "wrapper" com
> as convenções do jornal. No Mac, o Palmier pode substituir o motor (ver guia).
> Guia completo: `docs/PADRAO-EDICAO-PODCAST.md`.

## Pré-checagem

1. Confirme que a **video-use está instalada** (`~/.claude/skills/video-use`).
   Se não estiver, rode `bash scripts/setup-video-use.sh` ou siga o guia (seção 6.1).
2. Confirme `ffmpeg` disponível (`ffmpeg -version`).
3. Transcrição: prefira **ElevenLabs** se houver `ELEVENLABS_API_KEY`; senão use
   **Whisper local** (grátis) — instrua a video-use a usar whisper local.
4. Identifique os brutos: pode ser **1 arquivo** (só rosto, ou só áudio) ou
   **2 arquivos** (rosto separado + tela separada).

## Regras de corte (padrão do jornal)

Aplique via video-use:

- **Palavras-chave de erro** (procure na transcrição e corte):
  - `"pato amarelo"` → erro curto: remova o trecho errado imediatamente antes da
    palavra-chave; mantenha a repetição que vem depois.
  - `"pato vermelho"` → erro grande: descarte a frase/parágrafo inteiro anterior;
    mantenha o recomeço.
  - Remova **também a própria palavra-chave** do corte final.
- **Silêncios**: corte gaps de fala ≥ 400ms (seguro). Entre 150–400ms, corte com
  verificação visual. Mantenha respiros naturais — o padrão é **minimalista, não
  robótico**. Não deixe o áudio "picotado".
- **Muletas/hesitações** longas (é... hã... "deixa eu ver") no início de fala podem
  ser cortadas; não cace toda muleta a ponto de deixar artificial.
- **Nunca corte no meio de uma palavra** (a video-use já respeita limite de palavra).

## Regras de layout

Adapte ao formato do episódio — **pergunte só se não estiver claro**:

- **Solo + tela** (apresentador com câmera e captura de tela, ex.: notícias):
  - **Rosto inteiro** quando começa um novo bloco/tema/notícia (a pessoa está
    introduzindo o assunto, sem mostrar a tela).
  - **Rosto + tela** (PiP/split) quando está discorrendo e mostrando a tela.
  - Volte a **rosto inteiro** ao trocar de bloco. Use a transcrição para detectar
    as viradas de assunto.
- **2+ pessoas conversando**: alterne o enquadramento para **quem está falando**;
  em fala cruzada, use plano aberto com todos.
- **Só áudio**: sem layout de câmera — foque em corte de silêncio/erro e deixe
  pronto para a skill `podcast-cortes` adicionar legenda/waveform.

Se o layout já vem pronto do bruto (ex.: gravou com o **tela** que já compõe
rosto+tela), **preserve** esse layout e só faça os cortes.

## Fluxo

1. **Inventário**: `ffprobe` em cada bruto; transcreva (ElevenLabs ou whisper local);
   `pack_transcripts.py` para gerar a leitura.
2. **Plano**: descreva em 4–8 frases o que vai fazer (blocos detectados, onde estão
   os "patos", estratégia de layout, agressividade do corte de silêncio, duração
   estimada). **Espere a confirmação do usuário** antes de renderizar.
3. **Render**: gere o EDL e renderize por segmento → concat (a video-use já faz).
   Saída: `<pasta_do_bruto>/edit/final.mp4`.
4. **Auto-avaliação**: verifique cada corte (descontinuidade visual, estouro de
   áudio, alinhamento). Corrija e re-renderize (até 3 passadas); sinalize o que
   sobrar.
5. **Entrega**: informe o caminho do `final.mp4`, a duração final e um resumo dos
   cortes. Sugira rodar a skill **podcast-cortes** para gerar os verticais.

## Saídas

- `<pasta>/edit/final.mp4` — episódio editado (horizontal).
- `<pasta>/edit/master.srt` — legenda (se pedida).
- `<pasta>/edit/takes_packed.md` — transcrição usada.

## Especificações padrão

- **Aspecto**: mantém o do bruto (normalmente 1920×1080). Tela/tech: 30fps.
- **Legenda**: não queimar no episódio horizontal por padrão (fica para os cortes),
  salvo pedido do usuário.
- **Cor**: `neutral_punch` (correção mínima) salvo pedido diferente.

## Não faça

- Não re-transcrever sem necessidade (use cache).
- Não escrever fora de `edit/`.
- Não deixar o corte robótico atrás de "eficiência" — priorize naturalidade.
- Não inventar layout se o formato não estiver claro: pergunte em uma linha.
