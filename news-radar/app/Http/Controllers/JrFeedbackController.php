<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Voto humano no card do Radar. Atrás do JrPanelKey (fail-closed).
 * Re-voto = upsert na mesma linha; snapshot do juiz preservado do 1º contato
 * com o item no momento de CADA voto (último voto vence).
 */
class JrFeedbackController extends Controller
{
    private const FAIXAS = ['baixa' => 20, 'media' => 45, 'alta' => 80];

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'item_id' => 'required|integer',
            'faixa' => 'required|in:baixa,media,alta',
        ]);

        $item = DB::table('jr_link_extracao')->where('id', $dados['item_id'])
            ->first(['id', 'cluster_id', 'score_editorial', 'tipo_gancho']);
        if (! $item) {
            return response()->json(['error' => 'item não existe'], 404);
        }

        DB::table('jr_pauta_feedback')->upsert([[
            'jr_link_extracao_id' => $item->id,
            'cluster_id' => $item->cluster_id,
            'faixa' => $dados['faixa'],
            'ponto_medio' => self::FAIXAS[$dados['faixa']],
            'score_juiz_na_hora' => $item->score_editorial,
            'gancho_na_hora' => $item->tipo_gancho,
            'votado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['jr_link_extracao_id'], [
            'cluster_id', 'faixa', 'ponto_medio', 'score_juiz_na_hora',
            'gancho_na_hora', 'votado_em', 'updated_at',
        ]);

        return response()->json(['ok' => true, 'item_id' => $item->id, 'faixa' => $dados['faixa']]);
    }
}
