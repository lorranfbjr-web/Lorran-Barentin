<?php

namespace App\Http\Controllers;

use App\Services\Jr\EventClusterer;
use App\Services\Jr\JuizLlm;
use App\Services\Jr\PautaGate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Goal 4 — VERIFICADOR de potencial de pauta. Cola texto ou link → diz se vale,
 * por quê, o que falta, e quem mais publicou. Reusa a régua REAL do juiz
 * (julgarLote, sem bumpar prompt), a trava dura do PautaGate (vaquinha/golpe) e o
 * banco de raspagem (jr_link_extracao) pra "quem cobriu". NÃO publica nada.
 */
class JrVerificadorController extends Controller
{
    // Mesmo extrator do pipeline (jrlink:extract) — replicado aqui pra não tocar
    // o command. trafilatura local → Jina como fallback.
    private const PY = '/home/jr/jr-extract/venv/bin/python';
    private const SCRIPT = '/home/jr/jr-extract/jr_extract.py';

    public function __construct(private JuizLlm $juiz, private PautaGate $gate) {}

    public function form(Request $request): View
    {
        return view('verificador', ['key' => (string) $request->query('key', '')]);
    }

    public function verificar(Request $request): JsonResponse
    {
        $texto = trim((string) $request->input('texto', ''));
        $url = trim((string) $request->input('url', ''));
        $fonteExtra = null;

        if ($url !== '' && $texto === '') {
            $ext = $this->extrair($url);
            if (! $ext['ok'] || mb_strlen(trim($ext['text'])) < 120) {
                return response()->json(['error' => 'não consegui extrair o corpo do link (trafilatura/Jina). Cole o texto manualmente.'], 422);
            }
            $texto = trim($ext['text']);
            $fonteExtra = $ext['title'];
        }

        if (mb_strlen($texto) < 80) {
            return response()->json(['error' => 'cole um texto de pauta (mín. ~80 caracteres) ou um link válido.'], 422);
        }

        [$titulo, $lead] = $this->tituloLead($texto, $fonteExtra);

        // 1) Trava DURA determinística (vaquinha/Pix/golpe = NUNCA automático).
        [$gateRes, $gateMotivo] = $this->gate->avaliar($texto);

        // 2) Régua REAL do juiz (eh_pauta, score, gancho, escopo, cidade, motivo).
        $verdict = [];
        try {
            $vd = $this->juiz->julgarLote([['id' => 1, 'titulo' => $titulo, 'lead' => $lead]]);
            $verdict = $vd[1] ?? [];
        } catch (\Throwable $e) {
            $verdict = ['erro' => $e->getMessage()];
        }

        // 3) Análise de produção (lacunas, riscos SEO/plágio, DNA IG) — 1 call.
        $analise = $this->analisar($titulo, $texto);

        // 4) Quem mais publicou (banco de raspagem, sem LLM).
        $cobertura = $this->quemPublicou($titulo);

        return response()->json([
            'titulo_detectado' => $titulo,
            'gate' => $gateRes,
            'gate_motivo' => $gateMotivo,
            'solidariedade' => $gateRes === PautaGate::FILA_SOLIDARIEDADE,
            'veredito' => [
                'eh_pauta'    => (bool) ($verdict['eh_pauta'] ?? false),
                'score'       => (int) ($verdict['score_llm'] ?? 0),
                'escopo'      => $verdict['escopo'] ?? null,
                'tipo_gancho' => $verdict['tipo_gancho'] ?? null,
                'cidade'      => $verdict['cidade'] ?? null,
                'motivo'      => $verdict['motivo'] ?? ($verdict['erro'] ?? null),
            ],
            'analise' => $analise,
            'quem_publicou' => $cobertura,
        ]);
    }

    /** Extração trafilatura → Jina (mesmo chain do jrlink:extract). */
    private function extrair(string $url): array
    {
        try {
            $r = Process::timeout(50)->run([self::PY, self::SCRIPT, $url]);
            $json = json_decode(trim($r->output()), true);
            if (is_array($json) && ! empty($json['ok']) && mb_strlen(trim((string) ($json['text'] ?? ''))) >= 120) {
                return ['ok' => true, 'text' => (string) $json['text'], 'title' => $json['title'] ?? null];
            }
        } catch (\Throwable $e) {
            // cai pro Jina
        }
        $jinaKey = env('JINA_API_KEY');
        try {
            $headers = ['X-Return-Format' => 'markdown'];
            if (! empty($jinaKey)) {
                $headers['Authorization'] = 'Bearer ' . $jinaKey;
            }
            $resp = Http::withHeaders($headers)->timeout(45)->get('https://r.jina.ai/' . $url);
            $body = $resp->body();
            if ($resp->successful() && mb_strlen(trim($body)) >= 300) {
                return ['ok' => true, 'text' => $body, 'title' => null];
            }
        } catch (\Throwable $e) {
            // sem sorte
        }

        return ['ok' => false, 'text' => '', 'title' => null];
    }

    /** Análise de produção via LLM (lacunas/riscos/DNA IG). Reusa completarJson. */
    private function analisar(string $titulo, string $texto): array
    {
        $dna = $this->resumoDna();
        $corpo = mb_substr($texto, 0, 6000);
        $prompt = <<<PROMPT
Você é editor-chefe do Jornal Razão (Tijucas/SC, litoral e Vale do Itajaí). Avalie o MATERIAL abaixo como POTENCIAL DE PAUTA, no padrão do jornal e do perfil @jornalrazao no Instagram.

DNA do que engaja no @jornalrazao (medianas reais por tipo): {$dna}

Avalie e responda em JSON. Critérios:
- parece_jr: o material tem cara de post/matéria do Jornal Razão (regional SC, fato concreto, gancho real)? Não confunda SEO-washing ("tudo o que se sabe", "como foi") com pauta.
- forca: 0 a 100, o quão forte é como pauta pro perfil/site.
- por_que: 1-2 frases dizendo POR QUÊ vale ou não.
- lacunas: lista do que FALTA confirmar pra publicar (dados ausentes, o que está só na VERSÃO de uma parte e não foi atribuído/confirmado, fonte única).
- riscos: lista de riscos editoriais (SEO/clickbait, risco de PLÁGIO se copiar a fonte, falta de atribuição, fato x versão misturados, sensível).
- recomendacao: 1 frase — publicar / apurar mais / descartar, e o próximo passo.

RESPONDA APENAS com um array JSON de UM objeto, sem texto fora dele:
[{"parece_jr":true|false,"forca":0,"por_que":"...","lacunas":["..."],"riscos":["..."],"recomendacao":"..."}]

TÍTULO DETECTADO: {$titulo}

MATERIAL:
\"\"\"
{$corpo}
\"\"\"
PROMPT;

        try {
            $arr = $this->juiz->completarJson($prompt, 'verificador_pauta', 1, 'claude-opus-4-8');
            $r = $arr[0] ?? null;
            if (! is_array($r)) {
                return ['erro' => 'análise voltou vazia'];
            }
            $lista = fn ($v) => is_array($v) ? array_values(array_filter(array_map(fn ($x) => trim((string) $x), $v))) : [];

            return [
                'parece_jr'    => (bool) ($r['parece_jr'] ?? false),
                'forca'        => max(0, min(100, (int) ($r['forca'] ?? 0))),
                'por_que'      => trim((string) ($r['por_que'] ?? '')),
                'lacunas'      => $lista($r['lacunas'] ?? []),
                'riscos'       => $lista($r['riscos'] ?? []),
                'recomendacao' => trim((string) ($r['recomendacao'] ?? '')),
            ];
        } catch (\Throwable $e) {
            return ['erro' => $e->getMessage()];
        }
    }

    /** Resumo compacto do DNA do Instagram (tipos que mais engajam). */
    private function resumoDna(): string
    {
        $path = storage_path('app/jr-ig-dna.json');
        if (! is_file($path)) {
            return '(sem dados de DNA)';
        }
        $d = json_decode((string) file_get_contents($path), true);
        $tipos = $d['por_tipo'] ?? [];
        $linhas = [];
        foreach ($tipos as $t => $m) {
            $linhas[] = sprintf('%s (coment~%d, viewsReel~%d)', $t, (int) ($m['mediana_comentarios'] ?? 0), (int) ($m['mediana_views_reel'] ?? 0));
        }

        return mb_substr(implode('; ', $linhas), 0, 900);
    }

    /** Quem mais publicou: tokens distintivos do título → busca no banco. */
    private function quemPublicou(string $titulo): array
    {
        $clusterer = new EventClusterer();
        $tokens = array_keys($clusterer->tokens($titulo));
        // pega os tokens mais longos (mais distintivos) pra LIKE
        usort($tokens, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $fortes = array_slice(array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 4)), 0, 4);
        if (empty($fortes)) {
            return [];
        }

        $cut = Carbon::now()->subDays(30);
        $q = DB::table('jr_link_extracao')->where('duplicada', false)
            ->where('created_at', '>=', $cut)
            ->where(function ($w) use ($fortes) {
                foreach ($fortes as $t) {
                    $w->orWhere('titulo', 'like', '%' . $t . '%');
                }
            })
            ->limit(400)
            ->get(['host', 'url', 'titulo', 'data_pub', 'created_at']);

        // rankeia por nº de tokens distintivos compartilhados; >=2 = mesmo assunto.
        $tokSet = array_flip($fortes);
        $hits = [];
        foreach ($q as $row) {
            $tks = $clusterer->tokens((string) $row->titulo);
            $compart = 0;
            foreach (array_keys($tks) as $t) {
                if (isset($tokSet[$t])) {
                    $compart++;
                }
            }
            if ($compart >= 2) {
                $hits[$row->host][] = ['url' => $row->url, 'titulo' => $row->titulo, 'data' => $row->data_pub ?: (string) $row->created_at, 'match' => $compart];
            }
        }
        // melhor match por host
        $out = [];
        foreach ($hits as $host => $arts) {
            usort($arts, fn ($a, $b) => $b['match'] <=> $a['match']);
            $out[] = ['host' => $host, 'url' => $arts[0]['url'], 'titulo' => $arts[0]['titulo'], 'data' => $arts[0]['data']];
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['data'], (string) $a['data']));

        return array_slice($out, 0, 20);
    }

    /** @return array{0:string,1:string} */
    private function tituloLead(string $texto, ?string $tituloHint): array
    {
        $t = trim(preg_replace('/\s+/u', ' ', $texto));
        if ($tituloHint && mb_strlen(trim($tituloHint)) >= 12) {
            return [mb_substr(trim($tituloHint), 0, 140), mb_substr($t, 0, 280)];
        }
        if (preg_match('/^(.{20,140}?[.!?])\s/u', $t, $m)) {
            return [rtrim($m[1], '.!? '), mb_substr(trim(mb_substr($t, mb_strlen($m[1]))), 0, 280)];
        }

        return [mb_substr($t, 0, 120), mb_substr($t, 120, 280)];
    }
}
