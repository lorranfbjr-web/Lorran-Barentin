<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;

/**
 * Conector SAPL (Interlegis) — lê proposições de câmaras de SC pela REST pública.
 *
 *   /api/materia/materialegislativa/   matérias (paginação DRF, total_entries)
 *   /api/materia/tipomaterialegislativa/  tipos (id→sigla/descrição; VARIA/instância)
 *   /api/base/autor/                   autores (id→nome)
 *
 * READ-ONLY, educado: User-Agent identificável + pausa entre requisições.
 * ISOLADO: não toca DOM/juiz/captura. Resolve tipo/autor por instância (cache
 * em memória) e seleciona os tipos RELEVANTES (lei-making) por DESCRIÇÃO.
 */
class SaplConector
{
    private string $ua;

    private float $pausa;

    /** @var array<string,array> cache id→tipo por host */
    private array $tiposCache = [];

    /** @var array<string,array> cache id→autor por host */
    private array $autoresCache = [];

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? config('camara');
        $this->ua = (string) $cfg['user_agent'];
        $this->pausa = (float) $cfg['pausa_seg'];
    }

    private function get(string $host, string $path, array $query): ?array
    {
        try {
            $resp = Http::withHeaders(['User-Agent' => $this->ua])
                ->timeout(40)->retry(3, 2000, throw: false)
                ->get("https://{$host}/api/{$path}", $query + ['format' => 'json']);
            if (! $resp->ok()) {
                return null;
            }

            return $resp->json();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function dorme(): void
    {
        if ($this->pausa > 0) {
            usleep((int) ($this->pausa * 1_000_000));
        }
    }

    /**
     * Mapa id→['sigla','descricao'] dos tipos de matéria da instância.
     *
     * @return array<int,array{sigla:string,descricao:string}>
     */
    public function tipos(string $host): array
    {
        if (isset($this->tiposCache[$host])) {
            return $this->tiposCache[$host];
        }
        $j = $this->get($host, 'materia/tipomaterialegislativa/', ['limit' => 50]);
        $this->dorme();
        $map = [];
        foreach (($j['results'] ?? []) as $t) {
            $map[(int) $t['id']] = [
                'sigla' => (string) ($t['sigla'] ?? ''),
                'descricao' => (string) ($t['descricao'] ?? ''),
            ];
        }

        return $this->tiposCache[$host] = $map;
    }

    /**
     * Ids dos tipos RELEVANTES (lei-making) por DESCRIÇÃO normalizada — robusto a
     * variação de sigla/id entre instâncias.
     *
     * @param  array<int,string>  $alvos  substrings (já normalizadas) a casar
     * @return array<int,bool>  id => true
     */
    public function tiposRelevantes(string $host, array $alvos): array
    {
        $rel = [];
        foreach ($this->tipos($host) as $id => $t) {
            $d = $this->normaliza($t['descricao']);
            foreach ($alvos as $alvo) {
                if (str_contains($d, $alvo)) {
                    $rel[$id] = true;
                    break;
                }
            }
        }

        return $rel;
    }

    /** Mapa id→nome dos autores (resolve "autores": [ids]). */
    public function autores(string $host): array
    {
        if (isset($this->autoresCache[$host])) {
            return $this->autoresCache[$host];
        }
        $map = [];
        for ($page = 1; $page <= 10; $page++) {
            $j = $this->get($host, 'base/autor/', ['limit' => 200, 'page' => $page]);
            $this->dorme();
            $results = $j['results'] ?? [];
            foreach ($results as $a) {
                $nome = trim((string) ($a['nome'] ?? ''));
                if ($nome !== '') {
                    $map[(int) $a['id']] = $nome;
                }
            }
            $tp = (int) ($j['pagination']['total_pages'] ?? 1);
            if ($page >= $tp || ! $results) {
                break;
            }
        }

        return $this->autoresCache[$host] = $map;
    }

    /**
     * Itera matérias RELEVANTES de uma câmara (recente-primeiro). Itera UM tipo
     * por vez via filtro de API (?tipo=N) — porque as primeiras páginas do feed
     * geral são dominadas por indicação/moção (ruído), e os projetos de lei ficam
     * fundo na paginação. Resolve tipo/autor. $ano filtra um ano (forward); null
     * varre tudo (backfill). $maxPaginas é o teto POR TIPO.
     *
     * FORWARD: passe $jaVisto (fn(array $m): bool) e $pararVistos>0 — paginação
     * de CADA tipo para após N matérias já vistas consecutivas (recente-primeiro,
     * então o resto já está na base). O early-stop é POR TIPO (não atravessa pro
     * próximo), pra nunca pular um tipo só porque outro já estava cheio.
     *
     * @return \Generator<array>
     */
    public function materias(string $host, string $municipio, ?int $ano, int $maxPaginas, array $tiposAlvo, ?callable $jaVisto = null, int $pararVistos = 0): \Generator
    {
        $rel = $this->tiposRelevantes($host, $tiposAlvo);
        $tipos = $this->tipos($host);
        $autores = $this->autores($host);
        $orgao = 'Câmara Municipal de ' . $municipio;

        foreach (array_keys($rel) as $tid) {
            $seguidos = 0;
            for ($page = 1; $page <= $maxPaginas; $page++) {
                $q = ['limit' => 10, 'page' => $page, 'o' => '-ano,-numero', 'tipo' => $tid];
                if ($ano !== null) {
                    $q['ano'] = $ano;
                }
                $j = $this->get($host, 'materia/materialegislativa/', $q);
                if ($j === null) {
                    continue; // hiccup de rede — pula a página
                }
                $results = $j['results'] ?? [];
                if (! $results) {
                    break;
                }
                $pararTipo = false;
                foreach ($results as $m) {
                    $item = $this->montar($m, $host, $municipio, $orgao, $tipos[$tid] ?? null, $autores);
                    if ($jaVisto !== null && $jaVisto($item)) {
                        if ($pararVistos > 0 && ++$seguidos >= $pararVistos) {
                            $pararTipo = true;
                            break;
                        }

                        continue;
                    }
                    $seguidos = 0;
                    yield $item;
                }
                $tp = (int) ($j['pagination']['total_pages'] ?? 1);
                if ($pararTipo || $page >= $tp) {
                    break;
                }
                $this->dorme();
            }
        }
    }

    private function montar(array $m, string $host, string $municipio, string $orgao, ?array $tipo, array $autores): array
    {
        $sigla = $tipo['sigla'] ?? '';
        $desc = $tipo['descricao'] ?? '';
        $numero = (int) ($m['numero'] ?? 0);
        $ano = (int) ($m['ano'] ?? 0);
        $ementa = trim((string) ($m['ementa'] ?? ''));
        $indexacao = trim((string) ($m['indexacao'] ?? ''));

        $nomes = [];
        foreach (($m['autores'] ?? []) as $aid) {
            if (isset($autores[(int) $aid])) {
                $nomes[] = $autores[(int) $aid];
            }
        }

        $titulo = trim(($desc ?: $sigla) . " nº {$numero}/{$ano}");

        return [
            'host' => $host,
            'materia_id' => (int) $m['id'],
            'municipio' => $municipio,
            'orgao' => $orgao,
            'tipo_sigla' => $sigla ?: null,
            'tipo_descricao' => $desc ?: null,
            'complementar' => (bool) ($m['complementar'] ?? false),
            'numero' => $numero ?: null,
            'ano' => $ano ?: null,
            'ementa' => $ementa ?: null,
            'autores' => $nomes ? implode(', ', $nomes) : null,
            'em_tramitacao' => (bool) ($m['em_tramitacao'] ?? false),
            'data_pub' => $this->dataDe($m['data_apresentacao'] ?? null),
            'titulo' => $titulo,
            'url_fonte' => "https://{$host}/materia/" . (int) $m['id'],
            'url_pdf' => trim((string) ($m['texto_original'] ?? '')) ?: null,
            'texto_bruto' => trim($ementa . ($indexacao ? "\n[indexação] {$indexacao}" : '')) ?: null,
        ];
    }

    private function dataDe(?string $s): ?string
    {
        $s = trim((string) $s);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function normaliza(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $de = ['á','à','â','ã','ä','é','ê','ë','í','ï','ó','ô','õ','ö','ú','ü','ç'];
        $para = ['a','a','a','a','a','e','e','e','i','i','o','o','o','o','u','u','c'];

        return str_replace($de, $para, $s);
    }
}
