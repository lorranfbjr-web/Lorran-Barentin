<?php

namespace App\Services\Jr;

/**
 * FILTRO DE PRIVACIDADE da captura de WhatsApp (Parte A — opção B).
 *
 * Regra única, aplicada em DOIS pontos pra defesa em profundidade:
 *   1. rota POST /api/jr-pauta-capture  → não grava o JSON cru em disco;
 *   2. comando jrpauta:ingest           → não vira linha em jr_pauta_capturas.
 *
 * Aceita SOMENTE mensagem de GRUPO (is_group=1). Conversa individual nunca é
 * gravada. Corta ainda os chats da denylist nominal (config jrlink.captura.
 * ignorar_chats — inclui 'Raspador', a barreira ANTI-LOOP) e os grupos que
 * casam a denylist por regex (os 56 "#JRxx" de distribuição).
 *
 * NÃO usa o flag from_me como defesa anti-loop: o Z-API entrega mensagem da
 * própria instância com from_me=0. A barreira é o NOME do chat ('Raspador').
 */
class CapturaFiltro
{
    /**
     * @param  ?string  $chatName  nome do chat (grupo) — Z-API: chatName
     * @param  bool  $isGroup  Z-API: isGroup
     */
    public static function aceita(?string $chatName, bool $isGroup): bool
    {
        $cfg = config('jrlink.captura', []);

        // Parte A: só grupo.
        if (($cfg['somente_grupo'] ?? true) && ! $isGroup) {
            return false;
        }

        $nome = trim((string) $chatName);

        // Grupo sem nome (raro): mantém — é grupo, não vaza privado.
        if ($nome === '') {
            return $isGroup;
        }

        $alvo = mb_strtolower($nome);

        // Denylist nominal (anti-loop 'Raspador' + grupos pessoais).
        foreach ((array) ($cfg['ignorar_chats'] ?? []) as $ban) {
            if (mb_strtolower(trim((string) $ban)) === $alvo) {
                return false;
            }
        }

        // Denylist por regex (#JRxx de distribuição, etc.).
        foreach ((array) ($cfg['denylist_regex'] ?? []) as $rx) {
            if (@preg_match($rx, $nome) === 1) {
                return false;
            }
        }

        return true;
    }
}
