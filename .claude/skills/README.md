# Skills da Redação — Jornal Razão

Skills reutilizáveis do agente (Claude Code / Codex) para o fluxo de produção.

## Edição de podcast/vídeo

| Skill | Para que serve | Documentação |
|-------|----------------|--------------|
| **[podcast-edicao](./podcast-edicao/SKILL.md)** | Edita o bruto → episódio horizontal limpo (corta silêncios, erros, ajusta layout). | `docs/PADRAO-EDICAO-PODCAST.md` |
| **[podcast-cortes](./podcast-cortes/SKILL.md)** | Episódio editado → vários cortes verticais 9:16 com legenda, prontos p/ redes. | `docs/PADRAO-EDICAO-PODCAST.md` |

Ambas usam a ferramenta **[video-use](https://github.com/browser-use/video-use)**
por baixo. Instale uma vez com:

```bash
bash scripts/setup-video-use.sh
```

> A `video-use` em si é instalada como skill em `~/.claude/skills/video-use`
> (fora do repositório, por máquina). As skills acima apenas codificam as
> **convenções da redação** por cima dela.

## Como o agente descobre as skills

O Claude Code lê as skills em `.claude/skills/<nome>/SKILL.md`. O campo
`description` no frontmatter é o que faz o agente escolher a skill certa —
por isso ele é específico ("cortes", "clipes", "editar episódio" etc.).

Para usar, basta pedir em linguagem natural referenciando a skill, ex.:
*"use a skill podcast-edicao nos arquivos brutos do episódio 07"*.
