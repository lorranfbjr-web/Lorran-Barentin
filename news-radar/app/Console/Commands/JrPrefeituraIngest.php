<?php

namespace App\Console\Commands;

use App\Services\Jr\PrefeituraNewsConector;
use App\Services\Jr\Recencia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BLOCO 3 (Goal 02/07) — ingere notícias institucionais das prefeituras de
 * interesse (config/prefeitura.php) em jr_prefeitura_noticias. Forward-first,
 * dedup por hash(url), early-stop implícito (só as ~15 mais recentes por
 * fonte), data sanitizada (Recencia — BLOCO 1). Educado: 1 fonte por vez,
 * sleep 1s entre fontes. ADITIVO: não toca juiz/dispatcher/captura.
 */
class JrPrefeituraIngest extends Command
{
    protected $signature = 'jr:prefeitura-ingest {--cidade= : só esta cidade} {--dry : lista sem gravar}';

    protected $description = 'Ingere notícias institucionais das prefeituras de interesse (release oficial) — dedup por URL, data sanitizada';

    public function handle(PrefeituraNewsConector $conector): int
    {
        $max = (int) config('prefeitura.max_por_fonte', 15);
        $filtro = mb_strtolower((string) $this->option('cidade'));
        $totNovos = 0;
        $totVistos = 0;

        foreach ((array) config('prefeitura.fontes') as $fonte) {
            if (empty($fonte['ativo'])) {
                continue;
            }
            if ($filtro !== '' && mb_strtolower($fonte['cidade']) !== $filtro) {
                continue;
            }

            $novos = 0;
            $vistos = 0;
            try {
                foreach ($conector->noticias($fonte, $max) as $n) {
                    $hash = sha1('prefeitura:' . $n['url']);
                    if (DB::table('jr_prefeitura_noticias')->where('hash', $hash)->exists()) {
                        $vistos++;

                        continue;
                    }
                    if ($this->option('dry')) {
                        $this->line("  [dry] {$fonte['cidade']} · {$n['data_pub']} · " . mb_substr($n['titulo'], 0, 70));
                        $novos++;

                        continue;
                    }
                    DB::table('jr_prefeitura_noticias')->insert([
                        'source' => 'prefeitura',
                        'hash' => $hash,
                        'municipio' => $fonte['cidade'],
                        'orgao' => 'Prefeitura de ' . $fonte['cidade'],
                        'titulo' => mb_substr($n['titulo'], 0, 255),
                        'objeto' => $n['resumo'],
                        // BLOCO 1: data furada (futura/implausível) vira NULL + flag
                        ...Recencia::sanitizar($n['data_pub']),
                        'url_fonte' => mb_substr($n['url'], 0, 255),
                        'texto_bruto' => trim($n['titulo'] . "\n" . ($n['resumo'] ?? '')) ?: null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $novos++;
                }
            } catch (\Throwable $e) {
                $this->warn("  {$fonte['cidade']}: erro — " . mb_substr($e->getMessage(), 0, 140));
            }
            $this->line(sprintf('  %-24s %3d novos · %3d vistos', $fonte['cidade'], $novos, $vistos));
            $totNovos += $novos;
            $totVistos += $vistos;
            sleep(1); // educado entre fontes
        }

        $this->info(sprintf('Total: %d novos · %d vistos.', $totNovos, $totVistos));

        return self::SUCCESS;
    }
}
