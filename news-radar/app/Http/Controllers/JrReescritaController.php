<?php

namespace App\Http\Controllers;

use App\Services\Jr\RadarAssunto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

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
    /** Container: texto dos portais + links + reescrita existente (sem LLM). */
    public function mostrar(string $assuntoId): JsonResponse
    {
        $membros = RadarAssunto::membros($assuntoId);
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

    /**
     * Dispara a reescrita+entrega ASSÍNCRONA. Responde NA HORA ("mandando pro
     * grupo JR Rascunhos…") e o trabalho (og:image + Opus + envio Z-API) roda em
     * background (jrpauta:entregar via nohup). Manual + atrás de chave; NÃO
     * publica nada. O Opus continua sendo 1 chamada por clique.
     */
    public function reescrever(Request $request, string $assuntoId): JsonResponse
    {
        $membros = RadarAssunto::membros($assuntoId);
        if ($membros->isEmpty()) {
            return response()->json(['error' => 'assunto não encontrado'], 404);
        }
        $comTexto = $membros->filter(fn ($m) => trim((string) $m->markdown) !== '')->values();
        if ($comTexto->isEmpty()) {
            return response()->json(['error' => 'nenhum portal com texto extraído pra reescrever'], 422);
        }

        // Anti duplo-clique: se já está sendo montada agora (<2min), não dispara de novo.
        $atual = DB::table('jr_pauta_reescrita')->where('assunto_id', $assuntoId)->first();
        if ($atual && ($atual->entrega_status ?? null) === 'pendente' && $atual->updated_at
            && Carbon::parse($atual->updated_at)->gt(Carbon::now()->subSeconds(120))) {
            return response()->json([
                'status' => 'enviando',
                'mensagem' => '📤 já estou montando essa pauta — chega no grupo JR Rascunhos em instantes.',
            ]);
        }

        // Semeia a linha 'pendente' (NOT NULL preenchidos); o background atualiza.
        DB::table('jr_pauta_reescrita')->updateOrInsert(
            ['assunto_id' => $assuntoId],
            [
                'cluster_ids' => json_encode($membros->pluck('cluster_id')->filter()->unique()->values()),
                'fontes' => json_encode($comTexto->map(fn ($m) => ['host' => $m->host, 'url' => $m->url])->values()),
                'n_portais' => $comTexto->count(),
                'titulos' => json_encode([]), 'materia' => '', 'tags' => json_encode([]), 'lacunas' => json_encode([]),
                'gate' => 'ok', 'entrega_status' => 'pendente', 'entrega_erro' => null,
                'gerado_em' => Carbon::now(), 'updated_at' => Carbon::now(), 'created_at' => Carbon::now(),
            ]
        );

        $this->dispararBackground($assuntoId);

        return response()->json([
            'status' => 'enviando',
            'mensagem' => '📤 Mandando pro grupo JR Rascunhos… a pauta (com fotos) chega em ~30s.',
        ]);
    }

    /** nohup artisan jrpauta:entregar — fire-and-forget, responde imediato. */
    private function dispararBackground(string $assuntoId): void
    {
        $cmd = sprintf(
            'nohup %s %s jrpauta:entregar %s >> %s 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($assuntoId),
            escapeshellarg(storage_path('logs/jrpauta-entregar.log'))
        );
        Process::path(base_path())->run($cmd);
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
            'fotos'            => $dec($r->fotos ?? null),
            'n_portais'        => (int) $r->n_portais,
            'modelo'           => $r->modelo,
            'custo_usd'        => (float) $r->custo_usd,
            'gate'             => $r->gate,
            'entrega_status'   => $r->entrega_status ?? null,
            'entregue_em'      => $r->entregue_em ?? null,
            'gerado_em'        => $r->gerado_em,
        ];
    }
}
