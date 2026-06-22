<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envio Z-API para o grupo PRÓPRIO "JR Rascunhos" (entrega da pauta reescrita do
 * /radar). Reusa as MESMAS credenciais do RadarNotificador, mas com grupo
 * separado (config jrlink.rascunhos.grupo). NÃO toca o dispatcher n8n, NÃO toca
 * o grupo Raspador nem os 56 de produção. Kill switch: creds/grupo vazio = não
 * envia. Nunca lança — devolve ?messageId.
 */
class ZapRascunhos
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('jrlink.rascunhos', []);
    }

    public function configurado(): bool
    {
        foreach (['instance', 'token', 'client_token', 'grupo'] as $k) {
            if (empty($this->cfg[$k] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** POST send-text. Retorna messageId ou null. */
    public function texto(string $mensagem): ?string
    {
        return $this->post('send-text', ['phone' => $this->cfg['grupo'], 'message' => $mensagem]);
    }

    /** POST send-image (image = URL; Z-API baixa). caption identifica o portal. */
    public function imagem(string $imageUrl, string $caption = ''): ?string
    {
        return $this->post('send-image', [
            'phone' => $this->cfg['grupo'],
            'image' => $imageUrl,
            'caption' => $caption,
        ]);
    }

    private function post(string $endpoint, array $body): ?string
    {
        if (! $this->configurado()) {
            Log::warning('[ZapRascunhos] não configurado — envio ignorado.');

            return null;
        }
        $url = sprintf('https://api.z-api.io/instances/%s/token/%s/%s',
            $this->cfg['instance'], $this->cfg['token'], $endpoint);
        try {
            $r = Http::withHeaders(['Client-Token' => $this->cfg['client_token']])
                ->timeout(30)
                ->post($url, $body);
            if ($r->successful() && ($id = $r->json('messageId'))) {
                return (string) $id;
            }
            Log::warning('[ZapRascunhos] ' . $endpoint . ' falhou: HTTP ' . $r->status() . ' ' . mb_substr($r->body(), 0, 200));
        } catch (\Throwable $e) {
            Log::warning('[ZapRascunhos] ' . $endpoint . ' erro: ' . $e->getMessage());
        }

        return null;
    }
}
