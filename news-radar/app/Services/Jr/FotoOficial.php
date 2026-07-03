<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BLOCO 8b (03/07) — FOTO OFICIAL do rascunho.
 *
 * Baixa a imagem principal da página da FONTE OFICIAL (release de prefeitura/
 * câmara): og:image primeiro, senão a 1ª imagem relevante do corpo. NUNCA
 * portal de notícia (direito autoral) — quem chama garante que url_fonte é
 * página de órgão público. Fotos de WhatsApp/captura continuam FORA (Trava #0).
 *
 * Validação: >30KB, menor dimensão >=400px, descarta logo/brasão/ícone
 * detectável pelo nome/URL. Binário vai pra storage/app/foto-oficial/<sha1>.<ext>
 * (storage, NÃO public/) — o hash permite reuso no upload pro WP (8c).
 * Crédito automático: "Divulgação/<órgão>". Nunca lança — null = sem foto.
 */
class FotoOficial
{
    private const DIR = 'foto-oficial';

    private const MIN_BYTES = 30 * 1024;

    private const MIN_DIM = 400;

    /** Padrões no caminho/nome que denunciam logo/brasão/enfeite, não foto. */
    private const NAO_FOTO = [
        'logo', 'brasao', 'bras%c3%a3o', 'icon', 'favicon', 'avatar',
        'placeholder', 'default', 'sprite', 'marca-', 'selo', 'timbre',
        'whatsapp-share', 'og-padrao',
    ];

    /**
     * Busca, valida e materializa a foto oficial da página da fonte.
     *
     * @param  string  $urlFonte  página do release no site do órgão
     * @param  string  $orgao  nome do órgão pro crédito (ex.: "Prefeitura de Tijucas")
     * @return ?array{path:string, abs:string, credito:string, bytes:int, largura:int, altura:int, origem:string}
     */
    public function buscar(string $urlFonte, string $orgao): ?array
    {
        $urlFonte = trim($urlFonte);
        if ($urlFonte === '' || ! preg_match('#^https?://#i', $urlFonte)) {
            return null;
        }

        $candidatas = [];
        if ($og = OgImage::de($urlFonte)) {
            $candidatas[] = $og;
        }
        // fallback: 1ª imagem relevante do corpo da página (mesmo fetch educado)
        foreach ($this->imagensDoCorpo($urlFonte) as $img) {
            if (! in_array($img, $candidatas, true)) {
                $candidatas[] = $img;
            }
        }

        foreach (array_slice($candidatas, 0, 3) as $url) {
            if ($this->pareceNaoFoto($url)) {
                continue;
            }
            sleep(1); // scraping educado: 1 req/s
            if ($foto = $this->materializar($url, $orgao)) {
                return $foto;
            }
        }

        return null;
    }

    /** Baixa, valida (>30KB, >=400px) e grava por hash. null = reprovada. */
    private function materializar(string $url, string $orgao): ?array
    {
        try {
            $r = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; JRbot/1.0; +https://jornaldetijucas.com.br)'])
                ->withOptions(['allow_redirects' => true])
                ->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $r->successful()) {
            return null;
        }

        $bin = $r->body();
        if (strlen($bin) < self::MIN_BYTES) {
            return null; // logo/thumb — foto de release real passa fácil de 30KB
        }
        $dim = @getimagesizefromstring($bin);
        if (! is_array($dim) || min((int) $dim[0], (int) $dim[1]) < self::MIN_DIM) {
            return null;
        }

        $ext = match ($dim[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };
        $rel = self::DIR.'/'.sha1($bin).'.'.$ext;
        $abs = storage_path('app/'.$rel);
        if (! is_file($abs)) {
            if (! is_dir(dirname($abs))) {
                @mkdir(dirname($abs), 0775, true);
            }
            file_put_contents($abs, $bin);
        }

        return [
            'path' => $rel,
            'abs' => $abs,
            'credito' => 'Divulgação/'.trim($orgao),
            'bytes' => strlen($bin),
            'largura' => (int) $dim[0],
            'altura' => (int) $dim[1],
            'origem' => $url,
        ];
    }

    /**
     * Imagens do corpo da página, em ordem de aparição, absolutizadas.
     * Filtra extensões não-imagem e padrões de logo. @return string[]
     */
    private function imagensDoCorpo(string $urlFonte): array
    {
        try {
            $r = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; JRbot/1.0; +https://jornaldetijucas.com.br)',
                    'Accept' => 'text/html',
                ])
                ->withOptions(['allow_redirects' => true])
                ->get($urlFonte);
        } catch (\Throwable) {
            return [];
        }
        if (! $r->successful()) {
            return [];
        }
        $html = mb_substr($r->body(), 0, 400000);

        if (! preg_match_all('#<img[^>]+src\s*=\s*["\']([^"\']+)["\'][^>]*>#i', $html, $m)) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $src) {
            $src = html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5);
            if (! preg_match('#\.(jpe?g|png|webp)(\?|$)#i', $src) || $this->pareceNaoFoto($src)) {
                continue;
            }
            if ($abs = $this->absolutizar($src, $urlFonte)) {
                $out[] = $abs;
            }
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    private function pareceNaoFoto(string $url): bool
    {
        $u = mb_strtolower($url);
        foreach (self::NAO_FOTO as $p) {
            if (str_contains($u, $p)) {
                Log::info('[FotoOficial] candidata descartada por padrão não-foto', ['url' => mb_substr($url, 0, 120), 'padrao' => $p]);

                return true;
            }
        }

        return false;
    }

    private function absolutizar(string $img, string $base): ?string
    {
        if (preg_match('#^https?://#i', $img)) {
            return $img;
        }
        if (str_starts_with($img, '//')) {
            return 'https:'.$img;
        }
        $p = parse_url($base);
        if (empty($p['scheme']) || empty($p['host'])) {
            return null;
        }

        return $p['scheme'].'://'.$p['host'].'/'.ltrim($img, '/');
    }
}
