<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * PEÇA 1 — captura salva a mídia.
 *
 * Casa fotos↔texto e baixa o binário das imagens das capturas de pauta. NÃO
 * altera o fluxo vivo de recebimento da Z-API: trabalha só sobre o que já está
 * em jr_pauta_capturas (as imagens já chegam ingeridas como tipo_conteudo
 * 'imagem', com a imageUrl no arquivo cru apontado por raw_path). Reprocessa
 * sob demanda e grava em jr_pauta_midia (tabela aditiva).
 *
 * Casamento foto↔texto:
 *  - legenda embutida: a captura de imagem já traz a legenda (texto) → a própria
 *    imagem é candidata da matéria.
 *  - sequência: texto e depois N fotos do MESMO grupo + MESMO remetente, em
 *    proximidade de tempo. Cada imagem é atribuída ao TEXTO mais próximo no
 *    tempo (do mesmo grupo+remetente), o que evita roubar fotos de um release
 *    vizinho do mesmo assessor.
 *
 * As imageUrl da Z-API são temporárias e EXPIRAM — por isso o download é feito
 * na hora do processamento e o resultado fica em disco.
 */
class PautaMidia
{
    /** Janela máxima (segundos) entre texto e foto candidata. */
    private int $janela;

    /** Diretório relativo (em storage/app) onde os binários ficam. */
    private const DIR = 'pauta-midia';

    /** Raiz absoluta dos caminhos relativos a storage/app (raw_path e mídia). */
    private function abs(string $rel): string
    {
        return storage_path('app/' . ltrim($rel, '/'));
    }

    public function __construct(int $janelaSegundos = 600)
    {
        $this->janela = $janelaSegundos;
    }

    /**
     * Casa e baixa as fotos de UMA captura de texto. Idempotente
     * (jr_pauta_midia.midia_message_id UNIQUE; só rebaixa o que faltou).
     *
     * @return array<int,object> linhas de jr_pauta_midia desta captura
     */
    public function processarCaptura(string $capturaMessageId): array
    {
        $cap = DB::table('jr_pauta_capturas')->where('message_id', $capturaMessageId)->first();
        if (! $cap) {
            return [];
        }

        $candidatas = $this->candidatasParaTexto($cap);

        foreach ($candidatas as $img) {
            $this->materializar($img, $cap, 'sequencia');
        }

        // Caso a própria captura seja uma imagem com legenda-release (legenda embutida).
        if ($cap->tipo_conteudo === 'imagem') {
            $this->materializar($cap, $cap, 'legenda_embutida');
        }

        return DB::table('jr_pauta_midia')
            ->where('captura_message_id', $capturaMessageId)
            ->orderBy('momment')
            ->get()
            ->all();
    }

    /**
     * Imagens (capturas tipo_conteudo='imagem') do mesmo grupo+remetente cuja
     * captura de TEXTO mais próxima no tempo seja justamente $cap. Isso impede
     * grudar numa matéria as fotos do release seguinte do mesmo assessor.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function candidatasParaTexto(object $cap): \Illuminate\Support\Collection
    {
        if ($cap->momment === null || $cap->chat_name === null) {
            return collect();
        }

        $low = $cap->momment - $this->janela * 1000;
        $high = $cap->momment + $this->janela * 1000;

        $base = DB::table('jr_pauta_capturas')
            ->where('chat_name', $cap->chat_name)
            ->where('sender_name', $cap->sender_name)
            ->whereBetween('momment', [$low, $high]);

        $imgs = (clone $base)->where('tipo_conteudo', 'imagem')
            ->get(['message_id', 'chat_name', 'sender_name', 'momment', 'raw_path', 'texto', 'tipo_conteudo']);

        // momments de TODAS as capturas de texto (do mesmo remetente) na janela —
        // pra decidir, por imagem, qual texto é o mais próximo.
        $textos = (clone $base)->where('tipo_conteudo', 'texto')
            ->whereRaw('LENGTH(COALESCE(texto, "")) >= 120')   // texto curto não é release
            ->pluck('momment')->all();

        return $imgs->filter(function ($img) use ($cap, $textos) {
            if ($img->momment === null) {
                return false;
            }
            $distAtual = abs($img->momment - $cap->momment);
            foreach ($textos as $tm) {
                if ($tm === $cap->momment) {
                    continue;
                }
                if (abs($img->momment - $tm) < $distAtual) {
                    return false; // outra captura de texto está mais perto desta foto
                }
            }

            return true;
        });
    }

    /**
     * Lê a imageUrl/caption do arquivo cru, baixa o binário e grava (ou atualiza)
     * a linha em jr_pauta_midia. Não rebaixa o que já está 'ok' em disco.
     */
    private function materializar(object $imgCap, object $textoCap, string $origem): void
    {
        $raw = $this->lerRaw($imgCap->raw_path ?? null);
        $imageBlock = $raw['image'] ?? null;
        if (! is_array($imageBlock)) {
            return;
        }

        $url = $imageBlock['imageUrl'] ?? $imageBlock['thumbnailUrl'] ?? null;
        $caption = $imageBlock['caption'] ?? null;
        $mime = $imageBlock['mimeType'] ?? 'image/jpeg';

        $existe = DB::table('jr_pauta_midia')->where('midia_message_id', $imgCap->message_id)->first();
        if ($existe && $existe->download_status === 'ok' && $existe->arquivo_path
            && is_file($this->abs($existe->arquivo_path))) {
            return; // já baixada
        }

        $dt = ($imgCap->momment !== null && $textoCap->momment !== null)
            ? (int) round(($imgCap->momment - $textoCap->momment) / 1000)
            : null;

        // crédito: explícito na legenda > explícito no release > padrão do órgão
        // emissor (foto oficial de prefeitura sem crédito = creditar o órgão).
        $credito = $this->extrairCredito((string) ($caption ?? ''))
            ?? $this->extrairCredito((string) ($textoCap->texto ?? ''))
            ?? $this->creditoPadrao((string) ($imgCap->chat_name ?? ''));

        $row = [
            'captura_message_id' => $textoCap->message_id,
            'chat_name' => $imgCap->chat_name,
            'sender_name' => $imgCap->sender_name,
            'momment' => $imgCap->momment,
            'dt_segundos' => $dt,
            'origem' => $origem,
            'mime_type' => $mime,
            'width' => $imageBlock['width'] ?? null,
            'height' => $imageBlock['height'] ?? null,
            'caption' => $caption,
            'credito' => $credito,
            'source_url' => $url ? mb_substr($url, 0, 1024) : null,
            'updated_at' => now(),
        ];

        if (! $url) {
            $row['download_status'] = 'erro';
            $row['download_error'] = 'sem imageUrl no payload';
            $this->upsert($imgCap->message_id, $row);

            return;
        }

        $ext = str_contains($mime, 'png') ? 'png' : (str_contains($mime, 'webp') ? 'webp' : 'jpg');
        $dest = self::DIR . '/' . $textoCap->message_id . '/' . $imgCap->message_id . '.' . $ext;

        $dl = $this->baixar($url, $dest);
        $row['download_status'] = $dl['ok'] ? 'ok' : ($dl['expirado'] ? 'expirado' : 'erro');
        $row['download_error'] = $dl['error'];
        $row['arquivo_path'] = $dl['ok'] ? $dest : null;
        $row['bytes'] = $dl['bytes'];

        $this->upsert($imgCap->message_id, $row);
    }

    private function upsert(string $midiaMessageId, array $row): void
    {
        DB::table('jr_pauta_midia')->updateOrInsert(
            ['midia_message_id' => $midiaMessageId],
            array_merge($row, ['created_at' => now()])
        );
    }

    /** Baixa o binário. @return array{ok:bool,bytes:?int,error:?string,expirado:bool} */
    public function baixar(string $url, string $destRelativo): array
    {
        try {
            $resp = Http::timeout(45)->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'bytes' => null, 'error' => $e->getMessage(), 'expirado' => false];
        }

        if (! $resp->successful()) {
            // 403/404 em URL temporária = expirada.
            $expirado = in_array($resp->status(), [403, 404, 410], true);

            return ['ok' => false, 'bytes' => null, 'error' => 'HTTP ' . $resp->status(), 'expirado' => $expirado];
        }

        $body = $resp->body();
        if (strlen($body) < 512) {
            return ['ok' => false, 'bytes' => strlen($body), 'error' => 'binário suspeito (< 512 bytes)', 'expirado' => false];
        }

        $abs = $this->abs($destRelativo);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0775, true);
        }
        file_put_contents($abs, $body);

        return ['ok' => true, 'bytes' => strlen($body), 'error' => null, 'expirado' => false];
    }

    /**
     * Extrai o crédito da foto de um texto/legenda. Obrigatório preservar
     * (uso de foto oficial sem crédito é uso indevido). Pega padrões comuns dos
     * releases: "Crédito: …", "Foto: …", "Divulgação/…", "Secom …".
     */
    public function extrairCredito(string $texto): ?string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        $padroes = [
            '/(?:cr[eé]dito|foto|fotos|imagem|imagens)\s*[:\-]\s*([^\n\r]{2,80})/iu',
            '/\b(Divulga[cç][aã]o\s*\/\s*[A-ZÁ-Ú][\wÁ-úç ]{1,40})/u',
            '/\b(Secom\s+[A-ZÁ-Ú][\wÁ-úç ]{1,40})/u',
        ];
        foreach ($padroes as $re) {
            if (preg_match($re, $texto, $m)) {
                $cred = trim(rtrim($m[1], ' .;'));
                // limpa cauda quando o regex pegou frase longa
                $cred = preg_split('/[\n\r]|(?<=\.)\s/u', $cred)[0];

                return mb_substr($cred, 0, 120);
            }
        }

        return null;
    }

    /**
     * Crédito padrão do órgão emissor quando o release não traz crédito
     * explícito. Foto vinda de grupo oficial de assessoria sem crédito é
     * creditada ao órgão (prática jornalística). O revisor (Peça 4) ainda
     * sinaliza se faltar crédito.
     */
    public function creditoPadrao(string $chatName): ?string
    {
        $mapa = [
            'Prefeitura BC Imprensa' => 'Divulgação / Prefeitura de Balneário Camboriú',
            'SECOM Itajaí - Imprensa' => 'Divulgação / Secom Itajaí',
            'SECOM - Joinville' => 'Divulgação / Secom Joinville',
            'Prefeitura de Itapema - Imprensa' => 'Divulgação / Prefeitura de Itapema',
            'Imprensa - Prefeitura de São José' => 'Divulgação / Prefeitura de São José',
            'IMPRENSA - Pref. Tijucas' => 'Divulgação / Prefeitura de Tijucas',
            'Pref. Palhoça - Imprensa' => 'Divulgação / Prefeitura de Palhoça',
            'Mídia - Proteção e Defesa Civil SC' => 'Divulgação / Defesa Civil SC',
            'Imprensa e PMF 2' => 'Divulgação / Prefeitura de Florianópolis',
            'Mídias TV / Rádio / Sites - Secom SC' => 'Divulgação / Secom SC',
        ];

        return $mapa[$chatName] ?? null;
    }

    /** @return array dados do arquivo cru ('all'), ou [] */
    private function lerRaw(?string $rawPath): array
    {
        if (! $rawPath || ! is_file($this->abs($rawPath))) {
            return [];
        }
        $json = json_decode((string) file_get_contents($this->abs($rawPath)), true);

        return is_array($json) && isset($json['all']) && is_array($json['all']) ? $json['all'] : [];
    }
}
