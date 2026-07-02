<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entrega via TELEGRAM (bot do JR) pro chat dos alertas do Radar Cívico — o
 * MESMO bot/chat do jrcivico:alertar (config radar_civico.telegram). Nasceu no
 * A7 (02/07/2026): a entrega de rascunho por Z-API era INVIÁVEL (o número do
 * Lorran É a instância 276 de captura; a 884 é do disparador — ambas proibidas).
 * Decisão registrada: rascunho sai por Telegram.
 *
 * Fail-closed: sem token/chat_id, não envia (devolve null). Nunca lança.
 * Mensagens longas são fatiadas em ~4000 chars (teto do Telegram é 4096).
 */
class TelegramCivico
{
    private string $token;

    private string $chat;

    public function __construct()
    {
        $this->token = (string) config('radar_civico.telegram.token', '');
        $this->chat = (string) config('radar_civico.telegram.chat_id', '');
    }

    public function configurado(): bool
    {
        return $this->token !== '' && $this->chat !== '';
    }

    /**
     * Envia texto puro (sem parse_mode — rascunho é prosa, não HTML).
     * Retorna o message_id do PRIMEIRO pedaço, ou null se não enviou.
     */
    public function texto(string $mensagem): ?string
    {
        if (! $this->configurado()) {
            Log::warning('[TelegramCivico] não configurado — envio ignorado.');

            return null;
        }

        $primeiro = null;
        foreach ($this->fatiar($mensagem) as $pedaco) {
            try {
                $r = Http::asJson()->timeout(15)->post(
                    "https://api.telegram.org/bot{$this->token}/sendMessage",
                    ['chat_id' => $this->chat, 'text' => $pedaco, 'disable_web_page_preview' => true]
                );
                if ($r->successful() && ($r->json('ok') ?? false)) {
                    $primeiro ??= (string) $r->json('result.message_id');

                    continue;
                }
                Log::warning('[TelegramCivico] sendMessage falhou: HTTP ' . $r->status() . ' ' . mb_substr($r->body(), 0, 200));

                return $primeiro;
            } catch (\Throwable $e) {
                Log::warning('[TelegramCivico] sendMessage erro: ' . $e->getMessage());

                return $primeiro;
            }
        }

        return $primeiro;
    }

    /** @return array<int,string> pedaços de até ~4000 chars, quebrando em parágrafo. */
    private function fatiar(string $texto, int $max = 4000): array
    {
        $texto = trim($texto);
        if (mb_strlen($texto) <= $max) {
            return [$texto];
        }
        $out = [];
        $atual = '';
        foreach (preg_split('/\n{2,}/', $texto) as $par) {
            $cand = $atual === '' ? $par : $atual . "\n\n" . $par;
            if (mb_strlen($cand) > $max && $atual !== '') {
                $out[] = $atual;
                $atual = $par;
            } else {
                $atual = mb_strlen($cand) > $max ? mb_substr($cand, 0, $max) : $cand;
            }
        }
        if ($atual !== '') {
            $out[] = $atual;
        }

        return $out;
    }
}
