<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API da aba Radar do painel — pautas de jr_link_extracao com o veredito do
 * juiz. Quentes finais = coalesce(temperatura_juiz, temperatura); história
 * clusterizada conta 1x (representante). Tamanho do cluster vem num único
 * GROUP BY sobre os cluster_ids da página (sem N+1).
 */
class JrRadarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $secao = $request->query('secao', 'quentes');           // quentes | fila
        $janela = min(168, max(6, (int) $request->query('janela', 48)));
        $sort = $request->query('sort', 'score');               // score | recente
        $perPage = min(50, max(5, (int) $request->query('limit', 20)));

        $cutoff = now()->subHours($janela);
        $q = DB::table('jr_link_extracao')
            ->where('duplicada', false)
            ->where(function ($w) {
                $w->where('cluster_rep', true)->orWhereNull('cluster_id');
            })
            ->where(function ($w) use ($cutoff) {
                // data_pub é string heterogênea; ISO compara lexicográfico, resto cai no created_at
                $w->where('data_pub', '>=', $cutoff->toDateString())
                    ->orWhere(function ($ww) use ($cutoff) {
                        $ww->whereNull('data_pub')->where('created_at', '>=', $cutoff);
                    });
            });

        if ($secao === 'fila') {
            $q->where('temperatura_juiz', 'fila_humana');
        } else {
            $q->whereRaw("coalesce(temperatura_juiz, temperatura) = 'quente'");
        }

        if (in_array($request->query('eixo'), ['primaria', 'concorrente'], true)) {
            $q->where('eixo', $request->query('eixo'));
        }
        if (($min = (int) $request->query('score_min', 0)) > 0) {
            $q->where('score_editorial', '>=', $min);
        }
        if (($cidade = trim((string) $request->query('cidade'))) !== '') {
            $q->where('cidade_llm', 'like', '%' . $cidade . '%');
        }
        if (($busca = trim((string) $request->query('q'))) !== '') {
            $q->where(function ($w) use ($busca) {
                $w->where('titulo', 'like', '%' . $busca . '%')
                    ->orWhere('juiz_motivo', 'like', '%' . $busca . '%');
            });
        }

        if ($sort === 'recente') {
            $q->orderByRaw('coalesce(data_pub, created_at) desc');
        } else {
            $q->orderByRaw('coalesce(score_editorial, -1) desc')->orderByDesc('score');
        }

        $page = $q->paginate($perPage, [
            'id', 'titulo', 'url', 'host', 'fonte_tipo', 'origem', 'eixo',
            'temperatura', 'temperatura_juiz', 'score', 'score_editorial',
            'escopo', 'eh_pauta', 'tipo_gancho', 'cidade_llm', 'tema_ga4',
            'juiz_motivo', 'cluster_id', 'data_pub', 'created_at', 'notificado_em',
        ]);

        // Tamanho dos clusters da página — 1 query agregada, sem N+1.
        $clusterIds = collect($page->items())->pluck('cluster_id')->filter()->unique();
        $tamanhos = $clusterIds->isEmpty() ? collect() : DB::table('jr_link_extracao')
            ->whereIn('cluster_id', $clusterIds)
            ->selectRaw('cluster_id, count(*) n')->groupBy('cluster_id')->pluck('n', 'cluster_id');

        $data = collect($page->items())->map(function ($r) use ($tamanhos) {
            $r->cluster_n = $r->cluster_id ? (int) ($tamanhos[$r->cluster_id] ?? 1) : 1;
            $r->publicado_em = $r->data_pub ?: null;

            return $r;
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
