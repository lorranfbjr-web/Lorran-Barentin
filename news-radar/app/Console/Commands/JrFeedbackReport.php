<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Report de divergência juiz × humano sobre jr_pauta_feedback.
 * Divergência por voto = |score_juiz_na_hora − ponto_medio da faixa humana|.
 * Saída legível no terminal + JSON em storage/app/jr-feedback-report.json.
 */
class JrFeedbackReport extends Command
{
    protected $signature = 'jrlink:feedback-report';

    protected $description = 'Matriz juiz×humano, divergência média (geral, por gancho, por cidade) e top 10 divergências do feedback do Radar.';

    private const FAIXA_JUIZ = "case when score_juiz_na_hora < 30 then 'baixa' when score_juiz_na_hora < 60 then 'media' else 'alta' end";

    public function handle(): int
    {
        $votos = DB::table('jr_pauta_feedback as f')
            ->join('jr_link_extracao as e', 'e.id', '=', 'f.jr_link_extracao_id')
            ->get([
                'f.faixa', 'f.ponto_medio', 'f.score_juiz_na_hora', 'f.gancho_na_hora',
                'f.votado_em', 'e.titulo', 'e.cidade_llm', 'e.fonte_tipo', 'e.host', 'e.eixo',
            ]);

        if ($votos->isEmpty()) {
            $this->warn('Nenhum voto ainda — vote nos cards da aba Radar.');

            return self::SUCCESS;
        }

        $comJuiz = $votos->filter(fn ($v) => $v->score_juiz_na_hora !== null);
        $divs = $comJuiz->map(fn ($v) => abs((int) $v->score_juiz_na_hora - (int) $v->ponto_medio));

        // Matriz juiz×humano (3 faixas).
        $faixaJuiz = fn ($s) => $s === null ? null : ($s < 30 ? 'baixa' : ($s < 60 ? 'media' : 'alta'));
        $matriz = [];
        foreach (['baixa', 'media', 'alta'] as $j) {
            foreach (['baixa', 'media', 'alta'] as $h) {
                $matriz[$j][$h] = 0;
            }
        }
        foreach ($comJuiz as $v) {
            $matriz[$faixaJuiz((int) $v->score_juiz_na_hora)][$v->faixa]++;
        }

        $this->info(sprintf('FEEDBACK DO RADAR — %d votos (%d com veredito do juiz na hora)', $votos->count(), $comJuiz->count()));
        $this->newLine();
        $this->line('Matriz juiz (linha) × humano (coluna):');
        $this->table(['juiz \\ humano', '❄️ baixa', '😐 media', '🔥 alta'], collect($matriz)->map(
            fn ($linha, $j) => [$j, $linha['baixa'], $linha['media'], $linha['alta']]
        )->values()->all());

        $this->line(sprintf('Divergência média: %.1f pontos · concordância de faixa: %d%%',
            $divs->avg() ?? 0,
            $comJuiz->count() ? (int) round(100 * $comJuiz->filter(fn ($v) => $faixaJuiz((int) $v->score_juiz_na_hora) === $v->faixa)->count() / $comJuiz->count()) : 0));

        // Top 10 divergências.
        $top = $comJuiz->sortByDesc(fn ($v) => abs((int) $v->score_juiz_na_hora - (int) $v->ponto_medio))->take(10);
        $this->newLine();
        $this->line('TOP 10 divergências (juiz vs humano):');
        foreach ($top as $v) {
            $this->line(sprintf('  juiz=%-3d humano=%-5s (%d)  [%s]  %s  · %s',
                $v->score_juiz_na_hora, $v->faixa, $v->ponto_medio, $v->gancho_na_hora ?? '-',
                mb_strimwidth((string) $v->titulo, 0, 64), $v->fonte_tipo ?? $v->host ?? '?'));
        }

        // Por gancho e por cidade.
        $porGancho = $comJuiz->groupBy(fn ($v) => $v->gancho_na_hora ?? '(sem)')->map(fn ($g) => [
            'n' => $g->count(),
            'div_media' => round($g->avg(fn ($v) => abs((int) $v->score_juiz_na_hora - (int) $v->ponto_medio)), 1),
        ])->sortByDesc('div_media');
        $porCidade = $comJuiz->filter(fn ($v) => $v->cidade_llm)->groupBy('cidade_llm')->map(fn ($g) => [
            'n' => $g->count(),
            'div_media' => round($g->avg(fn ($v) => abs((int) $v->score_juiz_na_hora - (int) $v->ponto_medio)), 1),
        ])->sortByDesc('div_media');

        $this->newLine();
        $this->line('Divergência média por gancho:');
        foreach ($porGancho as $g => $d) {
            $this->line(sprintf('  %-22s n=%-3d div=%.1f', $g, $d['n'], $d['div_media']));
        }
        $this->newLine();
        $this->line('Divergência média por cidade (top 10):');
        foreach ($porCidade->take(10) as $c => $d) {
            $this->line(sprintf('  %-22s n=%-3d div=%.1f', $c, $d['n'], $d['div_media']));
        }

        $json = [
            'gerado_em' => Carbon::now()->toIso8601String(),
            'total_votos' => $votos->count(),
            'com_juiz' => $comJuiz->count(),
            'divergencia_media' => round($divs->avg() ?? 0, 2),
            'matriz_juiz_x_humano' => $matriz,
            'top_divergencias' => $top->map(fn ($v) => [
                'titulo' => $v->titulo, 'score_juiz' => $v->score_juiz_na_hora,
                'faixa_humana' => $v->faixa, 'gancho' => $v->gancho_na_hora,
                'fonte' => $v->fonte_tipo ?? $v->host,
            ])->values()->all(),
            'por_gancho' => $porGancho->all(),
            'por_cidade' => $porCidade->all(),
        ];
        $path = storage_path('app/jr-feedback-report.json');
        file_put_contents($path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();
        $this->info('JSON: ' . $path);

        return self::SUCCESS;
    }
}
