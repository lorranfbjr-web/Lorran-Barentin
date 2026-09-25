# Cortes JR

Motor de cortes verticais (1080x1920) no padrão visual do Jornal Razão.

- Foto de contexto no topo com Ken Burns, logo JR branco e título em caixas azuis (#0061FF, Fira Sans 700)
- Entrevistado embaixo, enquadrado pelo rosto, com punch-in alternado a cada frase e nas emendas da própria fonte
- Legenda em blocos curtos (até 3 palavras), branca, Montserrat 800, caixa mista como falado, sem karaokê: legibilidade por halo em camadas e sombra suave
- Corte automático de silêncio (só pausas confirmadas no áudio, nunca palavra)
- Barra de progresso na costura, tarja de nome opcional, crédito de foto
- Áudio: EQ de voz, compressão e loudnorm em -14 LUFS
- Capa 1080x1920 gerada junto (`*_capa.png`)

## Instalação

Precisa de Python 3.10+ e ffmpeg no PATH.

```
pip install pillow numpy opencv-python-headless faster-whisper huggingface_hub
```

Fontes (Google Fonts, OFL) e o detector de rosto (OpenCV YuNet) são baixados na primeira execução.

## Uso

1. Transcrever o trecho (tempo em segundos do arquivo original):

```
python cortes/transcribe.py entrevista.mp4 corte1_palavras.json --start 754 --end 832
```

2. Criar o `corte1.json`:

```json
{
  "source": "entrevista.mp4",
  "words": "corte1_palavras.json",
  "t_in": 754, "t_out": 832,
  "headline": "Título do corte, quebrado automaticamente em linhas equilibradas",
  "photo": "foto_topo.jpg",
  "photo_credit": "Foto: Autor · Wikimedia Commons",
  "photo_focus": [0.5, 0.45],
  "name": "Nome do entrevistado",
  "emphasis": ["palavra", "outra"],
  "fix": {"errado": "certo"},
  "out": "saida/corte1.mp4"
}
```

Para conferir a transcrição, rode também com `--model large-v3` e compare: `python cortes/diffwords.py rapido.json v3.json`. Onde os modelos divergem é onde a legenda costuma errar.

3. Renderizar:

```
python cortes/jr_reels.py corte1.json
python cortes/qa_sheet.py saida/corte1.mp4 saida/corte1_folha.png
```

## Chaves opcionais

| chave | padrão | o que faz |
|---|---|---|
| `headline` | | texto ou lista de linhas (quebra manual) |
| `photo` | painel azul JR | foto do topo; sem foto usa painel azul com marca d'água |
| `role` | | segunda linha da tarja de nome |
| `emphasis` | | palavras que ganham um punch-in rápido de zoom quando são ditas (a legenda não muda de cor) |
| `fix` | | correções da transcrição (palavra ou frase) |
| `remove` | | trechos a remover, `[[ini, fim], ...]` em tempo do arquivo original |
| `trim` | `{"max_gap": 0.5, "keep_gap": 0.24}` | corte de silêncio; `{"enabled": false}` desliga |
| `zoom` | `{"wide": 1.0, "tight": 1.3, "max_shot": 4.8, "face_wide": 250, "face_tight": 330}` | enquadramento e ritmo dos punch-ins (altura do rosto em px na metade de baixo) |
| `photo_grade` | | `[contraste, saturação]` da foto do topo, ex. `[1.15, 1.2]` |
| `speaker` | `{"pick": "largest"}` | `left` ou `right` quando há duas pessoas no quadro |
| `cap_words` | `3` | máximo de palavras por bloco de legenda |
| `sfx` | `true` | whoosh sutil na entrada do título |
| `cover_t` | automático | segundo do corte usado na capa |
| `crf` / `preset` | `18` / `medium` | qualidade do H.264 |
