<?php

namespace App\Console\Commands;

use App\Services\Jr\JuizLlm;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DNA do Instagram @jornalrazao: classifica cada post do corpus por TIPO DE
 * PAUTA (driver LLM em lote, prompt próprio) e cruza com engajamento
 * (comments_count mediano; views de reel como secundária) → "tipo × mediana ×
 * % do feed" + top/flop 15 reais. JSON em storage/app/jr-ig-dna.json.
 */
class JrIgDna extends Command
{
    protected $signature = 'jrlink:ig-dna {--reclass : Re-classifica tudo (default: só posts sem tipo)}';

    protected $description = 'Deriva o DNA editorial do Instagram (tipo de pauta × engajamento) a partir de jr_ig_corpus.';

    // Taxonomia fechada olhando o corpus real do @jornalrazao.
    private const TIPOS = [
        'flagrante_policial', 'acidente_resgate', 'clima', 'superacao_historia',
        'evento_cultura', 'politica', 'servico_utilidade', 'viral_curiosidade',
        'economia_negocios', 'obito_luto', 'esporte', 'animal', 'institucional', 'outro',
    ];

    public function handle(): int
    {
        $q = DB::table('jr_ig_corpus');
        if (! $this->option('reclass')) {
            $q->whereNull('tipo_pauta');
        }
        $pendentes = $q->get(['id', 'legenda']);

        $juiz = new JuizLlm();
        $this->info(sprintf('Classificando %d posts (driver %s)…', $pendentes->count(), $juiz->driver()));

        foreach ($pendentes->chunk(30) as $chunk) {
            $lista = '';
            foreach ($chunk as $p) {
                $leg = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $p->legenda)), 0, 220);
                $lista .= sprintf("ID %d: %s\n", $p->id, $leg ?: '(sem legenda)');
            }
            $tipos = implode('|', self::TIPOS);
            $prompt = <<<PROMPT
Classifique cada post do Instagram de um jornal regional de SC pelo TIPO DE PAUTA. Tipos possíveis: {$tipos}.
- flagrante_policial: prisão/apreensão/operação policial · acidente_resgate: acidente, incêndio, resgate, morte violenta · superacao_historia: história humana com nome e arco (superação, gesto, conquista pessoal) · viral_curiosidade: inusitado/engraçado/bizarro · institucional: agenda de prefeitura/órgão sem fato (inauguração protocolar, campanha oficial) · servico_utilidade: prazo, vaga, interdição, utilidade direta.
RESPONDA APENAS array JSON: [{"id": <id>, "tipo": "<tipo>"}]

POSTS:

{$lista}
PROMPT;
            $res = $juiz->completarJson($prompt, 'ig_dna', $chunk->count());
            $ok = 0;
            foreach ($res as $r) {
                if (isset($r['id'], $r['tipo']) && in_array($r['tipo'], self::TIPOS, true)) {
                    DB::table('jr_ig_corpus')->where('id', (int) $r['id'])->update(['tipo_pauta' => $r['tipo']]);
                    $ok++;
                }
            }
            $this->line("  lote: {$ok}/{$chunk->count()} classificados");
        }

        $this->dna();

        return self::SUCCESS;
    }

    private function dna(): void
    {
        $posts = DB::table('jr_ig_corpus')->whereNotNull('tipo_pauta')->get([
            'tipo_pauta', 'comments_count', 'video_view_count', 'legenda', 'url', 'postado_em',
        ]);
        $total = max(1, $posts->count());

        $mediana = function ($valores) {
            $v = collect($valores)->sort()->values();
            $n = $v->count();
            if (! $n) {
                return 0;
            }

            return $n % 2 ? $v[intdiv($n, 2)] : (int) round(($v[$n / 2 - 1] + $v[$n / 2]) / 2);
        };

        $porTipo = $posts->groupBy('tipo_pauta')->map(function ($g) use ($total, $mediana) {
            $reels = $g->filter(fn ($p) => $p->video_view_count);

            return [
                'n' => $g->count(),
                'pct_feed' => round(100 * $g->count() / $total, 1),
                'mediana_comentarios' => $mediana($g->pluck('comments_count')),
                'mediana_views_reel' => $reels->isEmpty() ? null : $mediana($reels->pluck('video_view_count')),
            ];
        })->sortByDesc('mediana_comentarios');

        $this->newLine();
        $this->info(sprintf('DNA @jornalrazao — %d posts classificados', $posts->count()));
        $this->table(['tipo', 'n', '% feed', 'mediana comentários', 'mediana views (reel)'],
            $porTipo->map(fn ($d, $t) => [$t, $d['n'], $d['pct_feed'] . '%', $d['mediana_comentarios'], $d['mediana_views_reel'] ?? '—'])->values()->all());

        $top = $posts->sortByDesc('comments_count')->take(15);
        $flop = $posts->sortBy('comments_count')->take(15);
        $this->line('TOP 15 (comentários):');
        foreach ($top as $p) {
            $this->line(sprintf('  %4d [%s] %s', $p->comments_count, $p->tipo_pauta,
                mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $p->legenda), 0, 80)));
        }
        $this->newLine();
        $this->line('FLOP 15:');
        foreach ($flop as $p) {
            $this->line(sprintf('  %4d [%s] %s', $p->comments_count, $p->tipo_pauta,
                mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $p->legenda), 0, 80)));
        }

        file_put_contents(storage_path('app/jr-ig-dna.json'), json_encode([
            'gerado_em' => Carbon::now()->toIso8601String(),
            'posts' => $posts->count(),
            'por_tipo' => $porTipo->all(),
            'top15' => $top->map(fn ($p) => ['comentarios' => $p->comments_count, 'tipo' => $p->tipo_pauta,
                'legenda' => mb_substr((string) $p->legenda, 0, 200), 'url' => $p->url])->values()->all(),
            'flop15' => $flop->map(fn ($p) => ['comentarios' => $p->comments_count, 'tipo' => $p->tipo_pauta,
                'legenda' => mb_substr((string) $p->legenda, 0, 200), 'url' => $p->url])->values()->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('JSON: ' . storage_path('app/jr-ig-dna.json'));
    }
}
