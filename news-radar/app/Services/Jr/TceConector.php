<?php

namespace App\Services\Jr;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Conector do DOTC-e (TCE-SC). Baixa o PDF de uma edição (URL datada,
 * dotc-e<AAAA-MM-DD>.pdf, Seg-Sex), entrega ao extrator Python (PyMuPDF) e
 * devolve os PROCESSOS/DECISÕES parseados.
 *
 * READ-ONLY, educado: UA identificável + pausa. ISOLADO. (Baixamos o PDF direto;
 * o índice /Diario/ tem shield anti-bot, mas o PDF datado é público e estável.)
 */
class TceConector
{
    private string $urlBase;

    private string $ua;

    private float $pausa;

    private string $python;

    private string $extrator;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? config('tce');
        $this->urlBase = (string) $cfg['url_base'];
        $this->ua = (string) $cfg['user_agent'];
        $this->pausa = (float) $cfg['pausa_seg'];
        $this->python = (string) $cfg['python'];
        $this->extrator = (string) $cfg['extrator'];
    }

    public function urlDaData(Carbon $data): string
    {
        return $this->urlBase . $data->toDateString() . '.pdf';
    }

    /**
     * Baixa e parseia a edição de uma data. [] se não há edição (404) ou falha.
     *
     * @return array<int,array>
     */
    public function decisoesDaData(Carbon $data): array
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
            return [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tce_') . '.pdf';
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
}
