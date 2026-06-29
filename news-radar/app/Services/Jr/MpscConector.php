<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Conector do Diário Oficial Eletrônico do MPSC. Baixa o PDF de uma edição
 * (URL datada, Seg-Sex), entrega o arquivo ao extrator Python (PyMuPDF, venv
 * dedicado) e devolve os EXTRATOS DE INSTAURAÇÃO parseados.
 *
 * READ-ONLY, educado: User-Agent identificável + pausa. ISOLADO.
 */
class MpscConector
{
    private string $urlBase;

    private string $ua;

    private float $pausa;

    private string $python;

    private string $extrator;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? config('mpsc');
        $this->urlBase = (string) $cfg['url_base'];
        $this->ua = (string) $cfg['user_agent'];
        $this->pausa = (float) $cfg['pausa_seg'];
        $this->python = (string) $cfg['python'];
        $this->extrator = (string) $cfg['extrator'];
    }

    public function urlDaData(Carbon $data): string
    {
        return $this->urlBase . $data->toDateString();
    }

    /**
     * Baixa e parseia a edição de uma data. Devolve [] se não há edição (fim de
     * semana/feriado → 404) ou se o parsing falhar.
     *
     * @return array<int,array>
     */
    public function extratosDaData(Carbon $data): array
    {
        $url = $this->urlDaData($data);

        try {
            $resp = Http::withHeaders(['User-Agent' => $this->ua])
                ->timeout(60)->retry(2, 2000, throw: false)
                ->get($url);
        } catch (\Throwable $e) {
            return [];
        }
        if ($this->pausa > 0) {
            usleep((int) ($this->pausa * 1_000_000));
        }
        if (! $resp->ok() || ! str_contains((string) $resp->header('Content-Type'), 'pdf')) {
            return []; // sem edição nesta data
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mpsc_') . '.pdf';
        file_put_contents($tmp, $resp->body());

        try {
            $r = Process::timeout(180)->run([
                $this->python, $this->extrator, $tmp, $url, $data->toDateString(),
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

    /** Comarca → município (a comarca "Capital" é Florianópolis). */
    public function municipioDaComarca(?string $comarca): ?string
    {
        if (! $comarca) {
            return null;
        }
        $c = trim($comarca);

        return mb_strtolower($c) === 'capital' ? 'Florianópolis' : $c;
    }
}
