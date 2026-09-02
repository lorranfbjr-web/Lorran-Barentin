# Lorran-Barentin — Gerador de Stories do Jornal Razão

App estático de arquivo único (`index.html`) que gera artes para Instagram do
Jornal Razão inteiramente no browser (Canvas 2D). Sem build, sem servidor,
sem dependências além de JSZip (cdnjs) e da fonte Montserrat (Google Fonts).

## Como rodar

Abrir `index.html` direto no navegador ou `python3 -m http.server` na raiz.
Não há testes: valide gerando as artes com e sem foto e conferindo os PNGs.

## Modelos (1080x1920, funções `renderModel*` no `<script>`)

| Modelo | Função | Uso |
|---|---|---|
| Diagonal Azul | `renderModel1` | dia a dia |
| Diagonal Branco | `renderModel2` | visual limpo |
| Foto Total | `renderModel3` | destaque, tragédias |
| Capa de Vídeo | `renderModelCapa` | fundo laranja `#FF9F00`, sem foto |

Foto é opcional: sem foto gera só a Capa de Vídeo; com foto gera os quatro.
Download individual em PNG e em lote via ZIP.

## Convenções que não podem quebrar

- **Logos embutidos em base64** dentro do HTML (evita CORS). Não trocar por
  URL remota. Recolor/crop/resize acontecem em `prepareLogos` no load.
- Aguardar `document.fonts` carregar Montserrat antes de renderizar.
- Manter o polyfill de `roundRect` e os `console.log('[JR] ...')` de diagnóstico.
- Montar cards com DOM API, não `innerHTML`; nada de template literal em
  handlers de evento.
- Paleta JR: Royal `#0D2481`, Sky `#0061FF`, Ciano `#18ADFE`, Arctic `#D9F8FF`.
- Ao mudar comportamento, subir a versão no `console.log` inicial e no
  indicador do footer (hoje v4) e descrever no commit, como nos anteriores.
