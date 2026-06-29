<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MESA DE PAUTA — Fase 5. Entrega de rascunho SÓ no WhatsApp do Lorran (DM, número
 * pessoal). Reusa a MESMA instância Z-API própria do Radar (a do alerta/notificação),
 * NÃO o disparador n8n 884, NÃO grupo nenhum. Kill switch fail-closed: sem
 * `radar_civico.rascunho.phone` (ou sem creds) NÃO envia — devolve null. Nunca
 * lança. O alvo é UM número só e vem do .env; nunca é inventado em código.
 */
class ZapLorran
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('radar_civico.rascunho', []);
    }

    public function configurado(): bool
    {
        foreach (['phone', 'instance', 'token', 'client_token'] as $k) {
            if (empty($this->cfg[$k] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Envia o texto pro número do Lorran. Retorna messageId ou null. */
    public function texto(string $mensagem): ?string
    {
        if (! $this->configurado()) {
            Log::info('[ZapLorran] sem número/credencial — rascunho não enviado (fail-closed).');

            return null;
        }

        $url = sprintf('https://api.z-api.io/instances/%s/token/%s/send-text',
            $this->cfg['instance'], $this->cfg['token']);

        try {
            $r = Http::withHeaders(['Client-Token' => $this->cfg['client_token']])
                ->timeout(30)
                ->post($url, ['phone' => $this->cfg['phone'], 'message' => $mensagem]);

            if ($r->successful() && ($id = $r->json('messageId'))) {
                return (string) $id;
            }
            Log::warning('[ZapLorran] send-text falhou: HTTP ' . $r->status() . ' ' . mb_substr($r->body(), 0, 200));
        } catch (\Throwable $e) {
            Log::warning('[ZapLorran] erro: ' . $e->getMessage());
        }

        return null;
    }
}
