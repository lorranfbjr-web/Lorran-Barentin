<?php

namespace App\Http\Controllers;

use App\Services\Jr\PautaGate;
use App\Services\Jr\PautaReescritor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Goal 3 — ferramenta de DECISÃO/PRODUÇÃO do Radar JR.
 *
 *  - mostrar(): container expansível. Lê o banco NA HORA (sem LLM): entrega o
 *    texto de TODOS os portais do assunto + os links de origem (pro Lorran abrir
 *    e pegar foto na fonte — o markdown não guarda imagem). Se já existe uma
 *    reescrita gravada, devolve junto.
 *  - reescrever(): junta o texto dos portais e gera UMA pauta padrão JR via LLM
 *    (claude-cli Opus). Disparo MANUAL e atrás de chave (JrPanelKey) — não roda
 *    no ciclo, não publica nada, só grava em jr_pauta_reescrita pro Lorran copiar.
 *
 * NÃO escreve no WP, NÃO toca o controle, NÃO publica. Vitrine de decisão.
 */
class JrReescritaController extends Controller
{
    public function __construct(private PautaReescritor $reescritor, private PautaGate $gate) {}

    /** Container: texto dos portais + links + reescrita existente (sem LLM). */
    public function mostrar(string $assuntoId): JsonResponse
    {
        $membros = $this->membrosDoAssunto($assuntoId);
        if ($membros->isEmpty()) {
            return response()->json(['error' => 'assunto não encontrado'], 404);
        }

        $portais = $membros->map(fn ($m) => [
            'host'       => $m->host,
            'fonte_tipo' => $m->fonte_tipo,
            'url'        => $m->url,
            'titulo'     => $m->titulo,
            'char_len'   => (int) $m->char_len,
            'tem_texto'  => trim((string) $m->markdown) !== '',
            'texto'      => trim((string) $m->markdown),
        ])->values();

        $reescrita = DB::table('jr_pauta_reescrita')->where('assunto_id', $assuntoId)->first();

        return response()->json([
            'assunto_id'   => $assuntoId,
            'n_portais'    => $portais->count(),
            'portais'      => $portais,
            'links_fontes' => $portais->pluck('url')->values(),
            // o extrato (trafilatura/Jina) NÃO guarda imagem — foto se pega na fonte.
            'sem_imagem_no_extrato' => true,
            'reescrita'    => $reescrita ? $this->formatarReescrita($reescrita) : null,
        ]);
    }

    /** Reescreve unificando os portais (LLM). Manual + atrás de chave. */
    public function reescrever(Request $request, string $assuntoId): JsonResponse
    {
        $membros = $this->membrosDoAssunto($assuntoId);
        if ($membros->isEmpty()) {
            return response()->json(['error' => 'assunto não encontrado'], 404);
        }

        $comTexto = $membros->filter(fn ($m) => trim((string) $m->markdown) !== '')->values();
        if ($comTexto->isEmpty()) {
            return response()->json(['error' => 'nenhum portal com texto extraído pra reescrever'], 422);
        }

        // Trava DURA solidariedade/vaquinha/Pix: NUNCA vira pauta automática —
        // vai pra fila humana (política JR). Crime/morte PASSA (é rascunho manual
        // de decisão, não auto-publicação; o radar é cheio de segurança legítima).
        $textotodo = $comTexto->map(fn ($m) => $m->titulo . "\n" . $m->markdown)->implode("\n\n");
        [$gateRes, $gateMotivo] = $this->gate->avaliar($textotodo);
        if ($gateRes === PautaGate::FILA_SOLIDARIEDADE) {
            DB::table('jr_pauta_reescrita')->updateOrInsert(
                ['assunto_id' => $assuntoId],
                [
                    'cluster_ids' => json_encode($membros->pluck('cluster_id')->filter()->unique()->values()),
                    'fontes' => json_encode($comTexto->map(fn ($m) => ['host' => $m->host, 'url' => $m->url])->values()),
                    'n_portais' => $comTexto->count(),
                    'cidade' => null, 'editoria' => null,
                    'titulos' => json_encode([]), 'titulo_principal' => null, 'linha_fina' => null,
                    'materia' => '', 'tags' => json_encode([]),
                    'lacunas' => json_encode([$gateMotivo, 'Solidariedade/vaquinha NÃO entra no automático — fila humana.']),
                    'modelo' => null, 'custo_usd' => 0, 'gate' => $gateRes,
                    'gerado_em' => Carbon::now(), 'updated_at' => Carbon::now(), 'created_at' => Carbon::now(),
                ]
            );

            return response()->json([
                'gate' => $gateRes,
                'motivo' => $gateMotivo,
                'reescrita' => null,
                'aviso' => 'Bloqueado pela trava de solidariedade/vaquinha — vai pra fila humana, não reescreve no automático.',
            ], 200);
        }

        $cidade = $comTexto->pluck('cidade_llm')->filter()->first();
        $portais = $comTexto->map(fn ($m) => [
            'host'   => (string) $m->host,
            'titulo' => (string) $m->titulo,
            'texto'  => (string) $m->markdown,
        ])->all();

        try {
            $r = $this->reescritor->reescreverUnificado($portais, $cidade);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'falha na reescrita: ' . $e->getMessage()], 500);
        }

        $custo = (float) DB::table('jr_juiz_log')->where('operation', 'reescrita_unificada')
            ->where('created_at', '>=', Carbon::now()->subMinutes(5))->sum('custo_usd');

        DB::table('jr_pauta_reescrita')->updateOrInsert(
            ['assunto_id' => $assuntoId],
            [
                'cluster_ids' => json_encode($membros->pluck('cluster_id')->filter()->unique()->values()),
                'fontes' => json_encode($comTexto->map(fn ($m) => ['host' => $m->host, 'url' => $m->url])->values()),
                'n_portais' => $comTexto->count(),
                'cidade' => $r['cidade'], 'editoria' => $r['editoria'],
                'titulos' => json_encode($r['titulos'], JSON_UNESCAPED_UNICODE),
                'titulo_principal' => $r['titulo_principal'],
                'linha_fina' => $r['linha_fina'],
                'materia' => $r['materia'],
                'tags' => json_encode($r['tags'], JSON_UNESCAPED_UNICODE),
                'lacunas' => json_encode($r['lacunas'], JSON_UNESCAPED_UNICODE),
                'modelo' => $r['modelo'], 'custo_usd' => $custo, 'gate' => 'ok',
                'gerado_em' => Carbon::now(), 'updated_at' => Carbon::now(), 'created_at' => Carbon::now(),
            ]
        );

        $reescrita = DB::table('jr_pauta_reescrita')->where('assunto_id', $assuntoId)->first();

        return response()->json([
            'gate' => 'ok',
            'custo_usd' => $custo,
            'reescrita' => $this->formatarReescrita($reescrita),
        ]);
    }

    /**
     * Membros (linhas) de um assunto: 'a...' = assunto_id; 'i<id>' = item solto.
     */
    private function membrosDoAssunto(string $assuntoId)
    {
        if (str_starts_with($assuntoId, 'i')) {
            $id = (int) substr($assuntoId, 1);

            return DB::table('jr_link_extracao')->where('id', $id)
                ->get(['id', 'cluster_id', 'host', 'fonte_tipo', 'url', 'titulo', 'markdown', 'char_len', 'cidade_llm', 'score']);
        }

        $clusterIds = DB::table('jr_link_extracao')->where('assunto_id', $assuntoId)
            ->whereNotNull('cluster_id')->distinct()->pluck('cluster_id');
        if ($clusterIds->isEmpty()) {
            return collect();
        }

        return DB::table('jr_link_extracao')->whereIn('cluster_id', $clusterIds)
            ->where('duplicada', false)
            ->orderByDesc('score')
            ->get(['id', 'cluster_id', 'host', 'fonte_tipo', 'url', 'titulo', 'markdown', 'char_len', 'cidade_llm', 'score'])
            ->unique('url')->values();
    }

    private function formatarReescrita(object $r): array
    {
        $dec = fn ($j) => json_decode((string) $j, true) ?: [];

        return [
            'titulos'          => $dec($r->titulos),
            'titulo_principal' => $r->titulo_principal,
            'linha_fina'       => $r->linha_fina,
            'materia'          => $r->materia,
            'tags'             => $dec($r->tags),
            'lacunas'          => $dec($r->lacunas),
            'cidade'           => $r->cidade,
            'editoria'         => $r->editoria,
            'fontes'           => $dec($r->fontes),
            'n_portais'        => (int) $r->n_portais,
            'modelo'           => $r->modelo,
            'custo_usd'        => (float) $r->custo_usd,
            'gate'             => $r->gate,
            'gerado_em'        => $r->gerado_em,
        ];
    }
}
