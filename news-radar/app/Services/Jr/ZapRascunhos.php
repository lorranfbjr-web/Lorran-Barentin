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

    /**
     * POST send-text. Retorna messageId (da 1ª fatia) ou null.
     *
     * BLOCO 2 (02/07): aceita grupo alternativo (canal SUGESTÕES × RASCUNHOS,
     * config radar_civico.canais.*) e fatia mensagens >4000 chars em parágrafo
     * (mobile, escaneável). Sem $grupo, mantém o grupo padrão (compatível com
     * jrpauta:entregar).
     */
    public function texto(string $mensagem, ?string $grupo = null): ?string
    {
        $alvo = $grupo ?: ($this->cfg['grupo'] ?? '');
        if ($alvo === '') {
            Log::warning('[ZapRascunhos] sem grupo de destino — envio ignorado.');

            return null;
        }

        $primeiro = null;
        foreach ($this->fatiar($mensagem, 4000) as $fatia) {
            $id = $this->post('send-text', ['phone' => $alvo, 'message' => $fatia]);
            $primeiro ??= $id;
            if ($id === null) {
                break; // falhou — não insiste nas fatias seguintes
            }
        }

        return $primeiro;
    }

    /** Fatia em blocos <= $max, preferindo quebrar em parágrafo. @return string[] */
    private function fatiar(string $texto, int $max): array
    {
        if (mb_strlen($texto) <= $max) {
            return [$texto];
        }
        $out = [];
        $resto = $texto;
        while (mb_strlen($resto) > $max) {
            $janela = mb_substr($resto, 0, $max);
            $corte = mb_strrpos($janela, "\n\n") ?: mb_strrpos($janela, "\n") ?: $max;
            $out[] = rtrim(mb_substr($resto, 0, $corte));
            $resto = ltrim(mb_substr($resto, $corte));
        }
        if ($resto !== '') {
            $out[] = $resto;
        }

        return $out;
    }

    /**
     * POST send-image (image = URL; Z-API baixa). caption identifica o portal.
     * BLOCO 8 (03/07): aceita grupo alternativo, igual texto().
     */
    public function imagem(string $imageUrl, string $caption = '', ?string $grupo = null): ?string
    {
        return $this->post('send-image', [
            'phone' => $grupo ?: ($this->cfg['grupo'] ?? ''),
            'image' => $imageUrl,
            'caption' => $caption,
        ]);
    }

    /**
     * BLOCO 8a (03/07): send-image de ARQUIVO LOCAL (storage/, fora do público)
     * como data-URI base64 — a foto oficial do rascunho não passa por URL
     * pública nenhuma. null = falhou (arquivo inexistente ou Z-API recusou).
     */
    public function imagemArquivo(string $absPath, string $caption = '', ?string $grupo = null): ?string
    {
        if (! is_file($absPath)) {
            Log::warning('[ZapRascunhos] imagemArquivo: arquivo não existe — envio ignorado.');

            return null;
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($absPath) ?: 'image/jpeg') : 'image/jpeg';

        return $this->post('send-image', [
            'phone' => $grupo ?: ($this->cfg['grupo'] ?? ''),
            'image' => 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absPath)),
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
            Log::warning('[ZapRascunhos] '.$endpoint.' falhou: HTTP '.$r->status().' '.mb_substr($r->body(), 0, 200));
        } catch (\Throwable $e) {
            Log::warning('[ZapRascunhos] '.$endpoint.' erro: '.$e->getMessage());
        }

        return null;
    }
}
