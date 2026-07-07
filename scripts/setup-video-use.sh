#!/usr/bin/env bash
#
# setup-video-use.sh — instala/atualiza a video-use e liga ao agente (Claude Code).
# Padrão de edição de podcast do Jornal Razão. Ver docs/PADRAO-EDICAO-PODCAST.md
#
# Uso:
#   bash scripts/setup-video-use.sh
#
# Multiplataforma (Linux/macOS). No Windows use WSL ou Git Bash.

set -euo pipefail

REPO_URL="https://github.com/browser-use/video-use"
DEST="${VIDEO_USE_DIR:-$HOME/Developer/video-use}"
SKILLS_DIR="${CLAUDE_SKILLS_DIR:-$HOME/.claude/skills}"

info()  { printf '\033[1;34m[setup]\033[0m %s\n' "$*"; }
warn()  { printf '\033[1;33m[aviso]\033[0m %s\n' "$*"; }
err()   { printf '\033[1;31m[erro]\033[0m %s\n' "$*" >&2; }

# 1. Pré-requisitos ----------------------------------------------------------
info "Verificando pré-requisitos..."
command -v git >/dev/null 2>&1 || { err "git não encontrado. Instale o git."; exit 1; }

if ! command -v ffmpeg >/dev/null 2>&1; then
  warn "ffmpeg não encontrado (OBRIGATÓRIO para renderizar)."
  warn "  Linux: sudo apt install ffmpeg   |   macOS: brew install ffmpeg"
fi

if ! command -v uv >/dev/null 2>&1 && ! command -v pip >/dev/null 2>&1; then
  warn "Nem 'uv' nem 'pip' encontrados — instale um deles para as dependências Python."
fi

# 2. Clonar ou atualizar -----------------------------------------------------
if [ -d "$DEST/.git" ]; then
  info "video-use já existe em $DEST — atualizando..."
  git -C "$DEST" pull --ff-only || warn "Não foi possível atualizar (siga manualmente)."
else
  info "Clonando video-use em $DEST..."
  mkdir -p "$(dirname "$DEST")"
  git clone "$REPO_URL" "$DEST"
fi

# 3. Ligar ao agente (symlink na pasta de skills) ----------------------------
info "Ligando a skill ao agente em $SKILLS_DIR/video-use..."
mkdir -p "$SKILLS_DIR"
ln -sfn "$DEST" "$SKILLS_DIR/video-use"

# 4. Dependências Python -----------------------------------------------------
info "Instalando dependências Python..."
if command -v uv >/dev/null 2>&1; then
  ( cd "$DEST" && uv sync ) || warn "uv sync falhou — tente 'pip install -e .' em $DEST"
elif command -v pip >/dev/null 2>&1; then
  ( cd "$DEST" && pip install -e . ) || warn "pip install falhou — verifique o Python."
fi

# 5. Arquivo de ambiente / transcrição --------------------------------------
if [ -f "$DEST/.env.example" ] && [ ! -f "$DEST/.env" ]; then
  cp "$DEST/.env.example" "$DEST/.env"
  info "Criado $DEST/.env — adicione sua ELEVENLABS_API_KEY (ou use Whisper local)."
fi

# 6. Resumo ------------------------------------------------------------------
cat <<EOF

✅ video-use pronta.

Próximos passos:
  • Transcrição (escolha UMA):
      (a) ElevenLabs: edite $DEST/.env com ELEVENLABS_API_KEY
      (b) Whisper local (grátis): peça ao agente "use whisper local para transcrever"
  • Para editar um episódio, abra o agente na pasta dos brutos e chame a skill:
      "use a skill podcast-edicao ..."   (ver docs/PADRAO-EDICAO-PODCAST.md)

EOF
