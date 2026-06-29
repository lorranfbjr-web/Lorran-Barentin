<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Conector TJSC (DJe) — EXPERIMENTAL. Baixa um caderno PDF de uma edição via
 * REST e entrega ao extrator Python (PyMuPDF), que aplica o filtro agressivo
 * (ente público + substância). READ-ONLY, educado. ISOLADO.
 *
 * ⚠️ Yield estruturalmente baixo (o DJe não traz texto de decisão) — usar só pela
 * sonda jr:tjsc-probe; não há ingestão em produção. Ver SCOPING-fase6-tjsc.md.
 */
class TjscConector
{
    private string $urlBase;

    private string $ua;

    private float $pausa;

    private string $python;

    private string $extrator;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? config('tjsc');
        $this->urlBase = (string) $cfg['url_base'];
        $this->ua = (string) $cfg['user_agent'];
        $this->pausa = (float) $cfg['pausa_seg'];
        $this->python = (string) $cfg['python'];
        $this->extrator = (string) $cfg['extrator'];
    }

    public function urlCaderno(int $edicao, int $cdCaderno): string
    {
        return $this->urlBase . '?edicao=' . $edicao . '&cdCaderno=' . $cdCaderno;
    }

    /**
     * Baixa e filtra um caderno. Devolve [] se o caderno é vazio/inexistente.
     *
     * @return array<int,array>
     */
    public function blocosRelevantes(int $edicao, int $cdCaderno): array
    {
        $url = $this->urlCaderno($edicao, $cdCaderno);

        try {
            $resp = Http::withHeaders(['User-Agent' => $this->ua])
                ->timeout(90)->retry(2, 2000, throw: false)
                ->get($url);
        } catch (\Throwable $e) {
            return [];
        }
        if ($this->pausa > 0) {
            usleep((int) ($this->pausa * 1_000_000));
        }
        if (! $resp->ok() || ! str_contains((string) $resp->header('Content-Type'), 'pdf') || strlen($resp->body()) < 5000) {
            return [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tjsc_') . '.pdf';
        file_put_contents($tmp, $resp->body());

        try {
            $r = Process::timeout(180)->run([
                $this->python, $this->extrator, $tmp, $url, (string) $edicao, (string) $cdCaderno,
            ]);
            if (! $r->successful()) {
                return [];
            }
            $json = json_decode(trim($r->output()), true);

            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            return [];
        } finally {
            @unlink($tmp);
        }
    }
}
