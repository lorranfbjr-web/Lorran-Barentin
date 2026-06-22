<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * PEÇA 2 — juiz visual (Opus vision) escolhe a melhor foto de capa.
 *
 * Dadas N fotos candidatas de uma matéria, manda cada uma pro Opus (o vision do
 * Claude já faz OCR — lê texto na imagem E avalia a cena) e devolve, em JSON:
 * qual foto vira capa (ou nenhuma), o crédito visível e a justificativa.
 *
 * Driver: claude-cli em modo headless usando a ferramenta Read pra "enxergar" o
 * arquivo local (é o caminho de LLM que existe HOJE nesta máquina — sem chave de
 * API). Mesmo modelo Opus da reescrita/juiz. Loga custo em jr_juiz_log.
 *
 * Régua de escolha:
 *  - PREFERIR: foto da CENA real (obra, evento, pódio, local), boa resolução,
 *    pouco texto sobreposto.
 *  - DESCARTAR: print de texto, card/arte com muita escrita, logo, foto
 *    borrada/escura, screenshot, imagem genérica sem relação com a matéria.
 *  - Se nenhuma presta → "nenhuma" (cai no fallback og:image).
 */
class JuizVisualFoto
{
    private string $modelo;

    public function __construct()
    {
        $this->modelo = (string) (config('jrlink.modelos.visual')
            ?? config('jrlink.modelos.juiz')
            ?? 'claude-opus-4-8');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * Escolhe a melhor foto entre as candidatas.
     *
     * @param  array<int,array{id:string,path:string,credito:?string}>  $fotos  (path absoluto)
     * @return array{escolhida_id:?string, credito:?string, justificativa:string, avaliacoes:array, custo:?float, ok:bool}
     */
    public function escolher(array $fotos, string $titulo, string $texto): array
    {
        $fotos = array_values(array_filter($fotos, fn ($f) => is_file($f['path'])));
        if (! $fotos) {
            return ['escolhida_id' => null, 'credito' => null, 'justificativa' => 'sem candidatas em disco', 'avaliacoes' => [], 'custo' => null, 'ok' => false];
        }

        // index 1..N → id, pra falar com o modelo sem vazar hashes longos.
        $mapa = [];
        $listaPaths = '';
        foreach ($fotos as $i => $f) {
            $n = $i + 1;
            $mapa[$n] = $f['id'];
            $listaPaths .= "FOTO {$n}: {$f['path']}\n";
        }

        $prompt = $this->montarPrompt($listaPaths, count($fotos), $titulo, $texto);

        $t0 = microtime(true);
        try {
            [$saida, $custo] = $this->chamarVision($prompt, count($fotos));
            $j = $this->parse($saida);
            $this->log('success', count($fotos), $custo, null, $t0);
        } catch (\Throwable $e) {
            $this->log('error', count($fotos), null, $e->getMessage(), $t0);

            return ['escolhida_id' => null, 'credito' => null, 'justificativa' => 'juiz visual falhou: ' . $e->getMessage(), 'avaliacoes' => [], 'custo' => null, 'ok' => false];
        }

        $escolhidaN = $j['escolhida'] ?? null;
        $escolhidaId = ($escolhidaN !== null && isset($mapa[$escolhidaN])) ? $mapa[$escolhidaN] : null;

        // crédito: o que o modelo viu na imagem, senão o crédito derivado da Peça 1.
        $credito = $j['credito'] ?: null;
        if (! $credito && $escolhidaId) {
            $credito = collect($fotos)->firstWhere('id', $escolhidaId)['credito'] ?? null;
        }

        return [
            'escolhida_id' => $escolhidaId,
            'credito' => $credito,
            'justificativa' => (string) ($j['justificativa'] ?? ''),
            'avaliacoes' => $j['avaliacoes'] ?? [],
            'custo' => $custo,
            'ok' => true,
        ];
    }

    private function montarPrompt(string $listaPaths, int $n, string $titulo, string $texto): string
    {
        $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', $texto)), 0, 1200);

        return <<<PROMPT
Você é o editor de fotografia do Jornal Razão (jornal regional de SC). Preciso escolher a FOTO DE CAPA de uma matéria. Use a ferramenta Read pra ABRIR e OLHAR cada arquivo de imagem listado abaixo (a visão já lê texto na imagem e avalia a cena).

MATÉRIA:
TÍTULO: {$titulo}
TEXTO (release): {$texto}

CANDIDATAS ({$n}):
{$listaPaths}
RÉGUA DE ESCOLHA:
- PREFIRA a foto que mostra a CENA real da matéria (obra, evento, pódio, local, pessoas em ação), com boa resolução e pouco/nenhum texto sobreposto.
- DESCARTE: print de texto, card/arte com muita escrita, logo/marca da prefeitura, foto borrada ou escura, screenshot de tela, imagem genérica sem relação com a matéria.
- Se NENHUMA candidata presta como capa, escolhida = null.
- CRÉDITO: se você LER algum crédito escrito na própria imagem (ex.: "Foto: Secom", "Divulgação/PMF"), devolva em "credito". Senão, deixe "credito" como null.

RESPONDA APENAS com UM objeto JSON válido, sem markdown e sem texto fora dele:
{"escolhida": <numero da foto 1-{$n} ou null>, "credito": "<crédito lido na imagem ou null>", "justificativa": "<1 frase curta>", "avaliacoes": [{"foto": <n>, "tipo": "cena_real|card_texto|logo|print|borrada|generica", "serve_capa": true|false, "motivo": "<curto>"}]}
PROMPT;
    }

    /** @return array{0:string,1:?float} [resultado, custo_usd] */
    private function chamarVision(string $prompt, int $n): array
    {
        // max-turns folgado: 1 Read por foto + raciocínio + resposta.
        $r = Process::timeout(600)->run([
            'claude', '-p', $prompt,
            '--model', $this->modelo,
            '--output-format', 'json',
            '--allowedTools', 'Read',
            '--permission-mode', 'bypassPermissions',
            '--max-turns', (string) ($n + 4),
        ]);

        if (! $r->successful()) {
            throw new \RuntimeException('claude-cli vision exit ' . $r->exitCode() . ': ' . mb_substr($r->errorOutput(), 0, 300));
        }

        $json = json_decode(trim($r->output()), true);
        if (! is_array($json) || ($json['is_error'] ?? false)) {
            throw new \RuntimeException('claude-cli vision retorno inválido: ' . mb_substr($r->output(), 0, 300));
        }

        return [(string) ($json['result'] ?? ''), isset($json['total_cost_usd']) ? (float) $json['total_cost_usd'] : null];
    }

    private function parse(string $texto): array
    {
        $texto = trim($texto);
        if (preg_match('/\{.*\}/s', $texto, $m)) {
            $texto = $m[0];
        }
        $arr = json_decode($texto, true);
        if (! is_array($arr)) {
            throw new \RuntimeException('JSON inválido do juiz visual: ' . mb_substr($texto, 0, 200));
        }
        $esc = $arr['escolhida'] ?? null;
        $arr['escolhida'] = ($esc === null || $esc === '' || $esc === 'null') ? null : (int) $esc;
        $arr['credito'] = isset($arr['credito']) && $arr['credito'] !== '' && $arr['credito'] !== 'null'
            ? mb_substr((string) $arr['credito'], 0, 120) : null;

        return $arr;
    }

    /**
     * PEÇA 2b — fallback og:image. Quando não há foto candidata boa MAS o release
     * traz link de artigo oficial, puxa a og:image (foto oficial e permanente).
     * Crédito = o próprio órgão do domínio. Retorna dados pra virar linha de mídia,
     * ou null (inclusive quando o domínio bloqueia bot — ex.: Cloudflare challenge).
     *
     * @return array{url:string, og_image:string, credito:string}|null
     */
    public function fallbackOgImage(string $texto): ?array
    {
        if (! preg_match('#https?://[^\s\)\]]+#u', $texto, $m)) {
            return null;
        }
        $url = rtrim($m[0], '.,;)');
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        // só domínios oficiais (.gov.br / órgão) — não puxar og:image de qualquer link.
        if (! preg_match('/\.gov\.br$/i', $host) && ! str_contains($host, 'gov')) {
            return null;
        }

        try {
            $resp = Http::timeout(25)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->get($url);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $resp->successful()) {
            return null; // 403 de WAF/Cloudflare cai aqui → vira pendência no relatório
        }

        $html = $resp->body();
        $og = null;
        if (preg_match('/<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $mm)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\']/i', $html, $mm)) {
            $og = html_entity_decode($mm[1]);
        }
        if (! $og) {
            return null;
        }

        return [
            'url' => $url,
            'og_image' => $og,
            'credito' => 'Divulgação / ' . $this->orgaoDoDominio($host),
        ];
    }

    private function orgaoDoDominio(string $host): string
    {
        $host = preg_replace('/^www\./', '', $host);
        $base = explode('.', $host)[0] ?? $host;
        $mapa = [
            'saojose' => 'Prefeitura de São José', 'pmf' => 'Prefeitura de Florianópolis',
            'balneariocamboriu' => 'Prefeitura de Balneário Camboriú', 'itajai' => 'Prefeitura de Itajaí',
            'joinville' => 'Prefeitura de Joinville', 'itapema' => 'Prefeitura de Itapema',
        ];

        return $mapa[$base] ?? ('Prefeitura (' . $host . ')');
    }

    private function log(string $status, int $itens, ?float $custo, ?string $erro, float $t0): void
    {
        DB::table('jr_juiz_log')->insert([
            'operation' => 'juiz_visual',
            'model' => $this->modelo,
            'status' => $status,
            'attempt' => 1,
            'itens' => $itens,
            'input_tokens' => null,
            'output_tokens' => null,
            'custo_usd' => $custo,
            'error_message' => $erro,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
