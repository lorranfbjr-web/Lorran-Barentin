<?php

namespace App\Http\Controllers;

use App\Services\Jr\CidadesInteresse;
use App\Services\Jr\DomGeografia;
use App\Services\Jr\RankingExibicao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RADAR CÍVICO DE SC — Fase 7 (integração). Une TODAS as fontes do radar cívico
 * num só lugar, server-rendered (lê as tabelas ao vivo, sem LLM no request):
 *
 *   📋 DOM/SC (licitações/compras) · 🏛️ Câmaras (proposições) ·
 *   ⚖️ MPSC (inquéritos) · 🧮 TCE (decisões/multas) · 🏛️ CINCATARINA (pin)
 *
 * Cada card mostra a FONTE; barra com FILTRO POR FONTE + dual-lens 🔴/🟢 + busca.
 * Dedup cross-fonte (best-effort) por assinatura município+objeto. Mobile-first,
 * V5.1 (navy/vermelho/verde/âmbar). ISOLADO: não toca juiz/radar editorial/captura.
 *
 * ⚖️ Cada item = FATO público + LEAD pra apurar, NUNCA acusação.
 */
class RadarCivicoController extends Controller
{
    private const CINCA_ORGAO = 'Interfederativo Santa Catarina';

    /** Teto de itens por PÁGINA (aceite: HTML < 300KB no 390px). Página com
     *  2 fontes (ex.: /justica) divide o teto entre elas. */
    private const LIMITE_PAGINA = 180;

    /**
     * GOAL SIMPLIFICAR (03/07): o hub-abas com iframes FALHOU no uso real
     * (mobile travava no iframe da vitrine). Páginas independentes, leves,
     * server-rendered, SEM iframe — reusando os MESMOS renderizadores/queries.
     */
    private const PAGINAS = [
        'dom' => ['fontes' => ['dom'], 'titulo' => '🧾 Diário Oficial — DOM/SC', 'sub' => 'licitações, compras e atos municipais'],
        'camaras' => ['fontes' => ['camara'], 'titulo' => '📜 Câmaras de SC', 'sub' => 'proposições das câmaras municipais (SAPL)'],
        'justica' => ['fontes' => ['mpsc', 'tce'], 'titulo' => '⚖️ Justiça — MPSC + TCE', 'sub' => 'inquéritos do MPSC e decisões/multas do TCE-SC'],
        'prefeituras' => ['fontes' => ['prefeitura'], 'titulo' => '📣 Prefeituras', 'sub' => 'releases oficiais — versão de uma parte, checar sempre'],
    ];

    /** fonte → página nova (redirects de links antigos + deep-links de alerta). */
    public const FONTE_PAGINA = [
        'dom' => '/dom', 'camara' => '/camaras', 'mpsc' => '/justica',
        'tce' => '/justica', 'prefeitura' => '/prefeituras',
    ];

    /**
     * /radar-civico agora é um ÍNDICE minimalista: 5 cartões-link (um por
     * página) com contador e "último item há X min" + link pra /mesa.
     * Links antigos do hub NÃO quebram: ?fonte= e ?painel= redirecionam.
     */
    public function index()
    {
        $fonte = (string) request()->query('fonte', '');
        if (isset(self::FONTE_PAGINA[$fonte])) {
            return redirect(self::FONTE_PAGINA[$fonte], 308);
        }
        $painel = (string) request()->query('painel', '');
        if ($painel === 'noticias') {
            return redirect('/radar', 308);
        }
        if ($painel === 'mesa') {
            return redirect('/mesa', 308);
        }

        return response($this->indiceHtml())
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Página independente por fonte: /dom · /camaras · /justica · /prefeituras. */
    public function pagina(string $slug)
    {
        $cfg = self::PAGINAS[$slug] ?? null;
        abort_unless($cfg, 404);

        $limite = intdiv(self::LIMITE_PAGINA, count($cfg['fontes']));
        $itens = [];
        foreach ($cfg['fontes'] as $f) {
            $itens = array_merge($itens, $this->{$f === 'camara' ? 'camara' : $f}($limite));
        }

        $itens = $this->dedup($itens);
        usort($itens, fn ($a, $b) => $b['score_x'] <=> $a['score_x']);

        // corte FINAL por página (coletar() devolve limite+150 pelas pernas
        // frescos/interesse): fresco SEMPRE sobrevive primeiro, arquivo completa.
        if (count($itens) > self::LIMITE_PAGINA) {
            $frescos = array_values(array_filter($itens, fn ($i) => $i['fresco']));
            $velhos = array_values(array_filter($itens, fn ($i) => ! $i['fresco']));
            $itens = array_slice(array_merge($frescos, $velhos), 0, self::LIMITE_PAGINA);
            usort($itens, fn ($a, $b) => $b['score_x'] <=> $a['score_x']);
        }

        $stats = $this->stats($itens);
        $json = json_encode($itens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // pré-seleção via URL (?cidades=interesse continua valendo por página)
        $intIni = request()->query('cidades') === 'interesse' ? '1' : '';

        $selecionados = [];
        if (DB::getSchemaBuilder()->hasTable('jr_pauta_fila')) {
            $selecionados = DB::table('jr_pauta_fila')->pluck('ato_ref')->all();
        }
        $selJson = json_encode($selecionados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response($this->html($json, $stats, $selJson, $intIni, $cfg['fontes'], $cfg['titulo'], $cfg['sub']))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Fonte → tabela (whitelist; usado pela íntegra do ato, Fase 2). */
    private const TABELAS = [
        'dom' => 'jr_dom_atos',
        'camara' => 'jr_camara_proposicoes',
        'mpsc' => 'jr_mpsc_extratos',
        'tce' => 'jr_tce_decisoes',
        'prefeitura' => 'jr_prefeitura_noticias',
    ];

    /**
     * FASE 2 — íntegra do ato (lazy). Lê texto_bruto (o trecho específico daquele
     * assunto, já parseado na ingestão) da tabela-fonte e devolve em JSON pro
     * container inline. Read-only, público como o radar. Mantém o PDF como link
     * secundário ("abrir original").
     */
    public function ato(string $source, int $id)
    {
        $tabela = self::TABELAS[$source] ?? null;
        if (! $tabela) {
            return response()->json(['error' => 'fonte inválida'], 404);
        }

        $cols = DB::getSchemaBuilder()->getColumnListing($tabela);
        $sel = array_values(array_intersect(['texto_bruto', 'url_pdf', 'url_fonte'], $cols));
        $row = DB::table($tabela)->where('id', $id)->first($sel);
        if (! $row) {
            return response()->json(['error' => 'ato não encontrado'], 404);
        }

        return response()->json([
            'texto' => trim((string) ($row->texto_bruto ?? '')) ?: null,
            'url_pdf' => $row->url_pdf ?? null,
            'url_fonte' => $row->url_fonte ?? null,
        ]);
    }

    // ───────────────────────── fontes → forma comum ─────────────────────────

    private function dom(int $limite): array
    {
        // união 3-pernas (frescos ∪ interesse ∪ top) — item de ontem/hoje e de
        // cidade de interesse SEMPRE chega ao JSON (fix do bug "Ontem = 0").
        $rows = RankingExibicao::coletar('jr_dom_atos',
            fn ($q) => $q->whereNotNull('score_pauta')->where('score_pauta', '>=', 40),
            $limite);

        return $rows->map(fn ($a) => $this->base('dom', $a) + [
            'orgao' => $a->orgao,
            'meta' => array_values(array_filter([
                $a->modalidade,
                $a->valor !== null ? 'R$ ' . number_format((float) $a->valor, 0, ',', '.') : null,
                $a->categoria,
            ])),
            'extra' => $a->fornecedor ? ('Fornecedor: ' . $a->fornecedor) : null,
            'cinca' => $a->orgao && mb_stripos($a->orgao, self::CINCA_ORGAO) !== false,
            'url_pdf' => $a->url_pdf,
        ])->all();
    }

    private function camara(int $limite): array
    {
        // Piso de recência: feeds mortos (SAPL parado — Canoinhas 2024, Tijucas
        // 2022, São José 2020) não são pauta atual; só proposição dos últimos N
        // meses entra no radar. (A ingestão já só puxa as VIVAS; isto protege o
        // que já está na base do backfill histórico.)
        $piso = now()->subMonths((int) config('camara.radar_meses', 18))->toDateString();
        $rows = RankingExibicao::coletar('jr_camara_proposicoes',
            fn ($q) => $q->whereNotNull('score_pauta')->where('score_pauta', '>=', 40)
                ->whereNotNull('data_pub')->where('data_pub', '>=', $piso),
            $limite);

        return $rows->map(fn ($a) => $this->base('camara', $a) + [
            'orgao' => $a->orgao,
            'meta' => array_values(array_filter([
                $a->tipo_descricao,
                $a->numero ? ('nº ' . $a->numero . '/' . $a->ano) : null,
            ])),
            'extra' => $a->autores ? ('Autoria: ' . $a->autores) : null,
            'url_pdf' => $a->url_pdf,
        ])->all();
    }

    private function mpsc(int $limite): array
    {
        $rotulos = [
            'inquerito_civil' => 'Inquérito Civil', 'noticia_de_fato' => 'Notícia de Fato',
            'procedimento_preparatorio' => 'Proc. Preparatório', 'procedimento_administrativo' => 'Proc. Administrativo',
            'pa_acompanhamento' => 'PA Acompanhamento',
        ];
        $rows = RankingExibicao::coletar('jr_mpsc_extratos',
            fn ($q) => $q->whereNotNull('score_pauta')->where('score_pauta', '>=', 40),
            $limite);

        return $rows->map(fn ($a) => $this->base('mpsc', $a) + [
            'orgao' => $a->orgao,
            'meta' => array_values(array_filter([
                $rotulos[$a->tipo_proc] ?? $a->tipo_proc,
                $a->numero,
            ])),
            'extra' => $a->partes ? ('Partes: ' . $a->partes) : null,
            'url_pdf' => null,
        ])->all();
    }

    private function tce(int $limite): array
    {
        $rows = RankingExibicao::coletar('jr_tce_decisoes',
            fn ($q) => $q->whereNotNull('score_pauta')->where('score_pauta', '>=', 40),
            $limite);

        return $rows->map(fn ($a) => $this->base('tce', $a) + [
            'orgao' => $a->unidade_gestora,
            'meta' => array_values(array_filter([
                $a->tipo_proc,
                $a->processo,
            ])),
            'extra' => $a->desfecho ? ('Decisão: ' . mb_substr($a->desfecho, 0, 220)) : null,
            'url_pdf' => null,
        ])->all();
    }

    /** BLOCO 3/4 (02/07): notícia institucional de prefeitura — release oficial (🟢). */
    private function prefeitura(int $limite): array
    {
        $rows = RankingExibicao::coletar('jr_prefeitura_noticias',
            fn ($q) => $q->whereNotNull('score_pauta')->where('score_pauta', '>=', 40),
            $limite);

        return $rows->map(fn ($a) => $this->base('prefeitura', $a) + [
            'orgao' => $a->orgao,
            'meta' => array_values(array_filter([
                'release oficial',
                $a->titulo ? mb_substr($a->titulo, 0, 90) : null,
            ])),
            'extra' => '⚠️ Nota da prefeitura = versão oficial de uma parte — checar de forma independente.',
            'url_pdf' => null,
        ])->all();
    }

    /** Campos comuns a todas as fontes. */
    private function base(string $source, $a): array
    {
        // exibição: tier de interesse + vago + fresco + score_x (score_pauta cru
        // permanece em 'score' — Mesa/alertas seguem nele). Merge EXPLÍCITO das
        // chaves (o operador + de array mantém a chave da ESQUERDA — mesma
        // pegadinha do 'cinca' documentada abaixo).
        $rx = RankingExibicao::avaliar((int) $a->score_pauta, $a->municipio, $a->data_pub, $a->objeto_limpo);

        // GOAL SIMPLIFICAR (03/07): campos longos APARADOS pro JSON da página
        // ficar leve (aceite < 300KB) — a íntegra continua no botão "ler a
        // íntegra do ato" (lazy) e o dado cru intacto no banco.
        $apurar = array_slice((array) json_decode($a->o_que_apurar ?: '[]', true), 0, 3);
        $apurar = array_map(fn ($x) => mb_substr((string) $x, 0, 140), $apurar);

        return [
            'source' => $source,
            'ato_ref' => $source . ':' . $a->id,   // chave estável p/ a Mesa de Pauta (★)
            'municipio' => $a->municipio,
            'regiao' => DomGeografia::regiao($a->municipio),
            'objeto' => $a->objeto_limpo ? mb_substr($a->objeto_limpo, 0, 240) : null,
            'data_pub' => $a->data_pub,
            'url_fonte' => $a->url_fonte,
            'score' => (int) $a->score_pauta,
            'tier' => $rx['tier'],
            // BLOCO 6 (03/07): anunciante ativo na cidade → badge 💰 + filtro.
            // SÓ exibição; não entra em score nenhum (nem no score_x acima).
            'anun' => CidadesInteresse::anunciante($a->municipio),
            'vago' => $rx['vago'],
            'fresco' => $rx['fresco'],
            'score_x' => $rx['score_x'],
            'tipo' => $a->tipo,
            'gancho_curto' => $a->gancho_curto ? mb_substr($a->gancho_curto, 0, 120) : null,
            'gancho' => $a->gancho ? mb_substr($a->gancho, 0, 220) : null,
            'tipo_gancho' => $a->tipo_de_gancho,
            'apurar' => $apurar,
            'angulo' => $a->angulo_sugerido ? mb_substr($a->angulo_sugerido, 0, 180) : null,
            // 'cinca' fica por conta de cada fonte (PHP `+` mantém a chave da
            // ESQUERDA; default aqui sobrescreveria o cinca=true do DOM).
        ];
    }

    private function dedup(array $itens): array
    {
        $vistos = [];
        $out = [];
        foreach ($itens as $it) {
            $sig = $it['source'] . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', trim(
                (string) $it['municipio'] . ' ' . mb_substr((string) $it['objeto'], 0, 60)
            )));
            if (isset($vistos[$sig])) {
                continue;
            }
            $vistos[$sig] = true;
            $out[] = $it;
        }

        return $out;
    }

    private function stats(array $itens): array
    {
        $por = ['dom' => 0, 'camara' => 0, 'mpsc' => 0, 'tce' => 0, 'prefeitura' => 0];
        $fisc = 0;
        $serv = 0;
        $cinca = 0;
        $interesse = 0;
        $anun = 0;
        foreach ($itens as $it) {
            $por[$it['source']] = ($por[$it['source']] ?? 0) + 1;
            $fisc += $it['tipo'] === 'fiscalizacao' ? 1 : 0;
            $serv += $it['tipo'] === 'servico' ? 1 : 0;
            $cinca += ! empty($it['cinca'] ?? null) ? 1 : 0;
            $interesse += ($it['tier'] ?? 0) > 0 ? 1 : 0;
            $anun += ! empty($it['anun']) ? 1 : 0;
        }

        return ['total' => count($itens), 'por' => $por, 'fisc' => $fisc, 'serv' => $serv, 'cinca' => $cinca, 'interesse' => $interesse, 'anun' => $anun];
    }

    /**
     * ÍNDICE /radar-civico — 5 cartões-link grandes, zero JS pesado (só o
     * reencaminhador de deep-link antigo #ato-…), mobile-first.
     */
    private function indiceHtml(): string
    {
        $gerado = Carbon::now()->format('d/m/Y H:i');

        // contador + "último item há X min" por página (queries baratas)
        $cards = [
            ['href' => '/radar', 'emoji' => '📰', 'nome' => 'Notícias', 'desc' => 'vitrine dos portais (juiz)'] + $this->pulsoNoticias(),
            ['href' => '/dom', 'emoji' => '🧾', 'nome' => 'Diário Oficial', 'desc' => 'licitações e atos (DOM/SC)'] + $this->pulso('jr_dom_atos'),
            ['href' => '/camaras', 'emoji' => '📜', 'nome' => 'Câmaras', 'desc' => 'proposições municipais'] + $this->pulso('jr_camara_proposicoes'),
            ['href' => '/justica', 'emoji' => '⚖️', 'nome' => 'Justiça', 'desc' => 'MPSC + TCE'] + $this->pulso('jr_mpsc_extratos', 'jr_tce_decisoes'),
            ['href' => '/prefeituras', 'emoji' => '📣', 'nome' => 'Prefeituras', 'desc' => 'releases oficiais'] + $this->pulso('jr_prefeitura_noticias'),
        ];

        $lis = '';
        foreach ($cards as $c) {
            $ult = $c['ultimo'] !== null ? 'último há ' . $c['ultimo'] : 'sem itens';
            $lis .= '<a class="c" href="' . $c['href'] . '"><span class="e">' . $c['emoji'] . '</span>'
                . '<span class="t"><b>' . $c['nome'] . '</b><small>' . $c['desc'] . '</small></span>'
                . '<span class="n">' . number_format($c['total'], 0, ',', '.') . '<small>' . $ult . '</small></span></a>' . "\n";
        }

        return <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Radar Cívico de SC</title>
<style>
:root{--navy:#0D2481;--ink:#16181d;--muted:#6b7280;--line:#e6e8ee;--bg:#f5f6fa}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink)}
header{background:var(--navy);color:#fff;padding:18px 16px}
header h1{margin:0;font-size:20px;font-weight:800}header .sub{font-size:12px;opacity:.85;margin-top:3px}
.wrap{max-width:560px;margin:0 auto;padding:14px}
.c{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:10px;text-decoration:none;color:var(--ink)}
.c:active{background:#eef1f8}
.e{font-size:28px;flex:0 0 auto}
.t{flex:1;min-width:0}.t b{font-size:16px;display:block}.t small{color:var(--muted);font-size:12px}
.n{text-align:right;font-weight:800;font-size:18px;color:var(--navy)}.n small{display:block;font-weight:600;font-size:10.5px;color:var(--muted)}
.mesa{display:block;text-align:center;background:var(--navy);color:#fff;font-weight:800;border-radius:14px;padding:15px;text-decoration:none;margin-top:14px}
footer{padding:16px;text-align:center;color:var(--muted);font-size:11px}
</style></head><body>
<header><h1>🛰️ Radar Cívico de SC</h1><div class="sub">Jornal Razão — uso editorial interno</div></header>
<div class="wrap">
{$lis}<a class="mesa" href="/mesa">📌 Mesa de Pauta ›</a>
</div>
<footer>gerado em {$gerado} · cada item é FATO público + lead pra apurar, nunca acusação</footer>
<script>
// deep-link antigo do hub (#ato-fonte-id) → página nova da fonte
if(location.hash.indexOf("#ato-")===0){var m={dom:"/dom",camara:"/camaras",mpsc:"/justica",tce:"/justica",prefeitura:"/prefeituras"};
var f=location.hash.split("-")[1];if(m[f])location.replace(m[f]+location.hash);}
</script>
</body></html>
HTML;
    }

    /** contagem + idade do item mais novo (data de ingestão) de 1..n tabelas. */
    private function pulso(string ...$tabelas): array
    {
        $total = 0;
        $max = null;
        foreach ($tabelas as $t) {
            $total += (int) DB::table($t)->whereNotNull('score_pauta')->where('score_pauta', '>=', 40)->count();
            $ult = DB::table($t)->max('created_at');
            if ($ult && (! $max || $ult > $max)) {
                $max = $ult;
            }
        }

        return ['total' => $total, 'ultimo' => $max ? Carbon::parse($max)->locale('pt_BR')->diffForHumans(null, true, true) : null];
    }

    /** pulso da vitrine de notícias (jr_link_extracao, quentes 48h). */
    private function pulsoNoticias(): array
    {
        $total = (int) DB::table('jr_link_extracao')
            ->where('temperatura_juiz', 'quente')
            ->where('created_at', '>=', Carbon::now()->subHours(48))
            ->count();
        $max = DB::table('jr_link_extracao')->max('created_at');

        return ['total' => $total, 'ultimo' => $max ? Carbon::parse($max)->locale('pt_BR')->diffForHumans(null, true, true) : null];
    }

    private function html(string $json, array $s, string $selJson, string $intIni, array $fontes, string $titulo, string $sub): string
    {
        $gerado = Carbon::now()->format('d/m/Y H:i');
        $p = $s['por'];

        // filtro por fonte SÓ quando a página tem >1 fonte (ex.: /justica)
        $srcsHtml = '';
        if (count($fontes) > 1) {
            $nomes = ['dom' => '🧾 DOM', 'camara' => '📜 Câmaras', 'mpsc' => '⚖️ MPSC', 'tce' => '💰 TCE', 'prefeitura' => '📣 Prefeituras'];
            $srcsHtml = '<div class="srcs"><button type="button" class="sb on" data-src="">Todas <b>' . $s['total'] . '</b></button>';
            foreach ($fontes as $f) {
                $srcsHtml .= '<button type="button" class="sb" data-src="' . $f . '">' . ($nomes[$f] ?? $f) . ' <b>' . ($p[$f] ?? 0) . '</b></button>';
            }
            $srcsHtml .= '</div>';
        }

        return <<<HTML
<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{$titulo} · Radar Cívico JR</title>
<style>
:root{--navy:#0D2481;--red:#E63946;--green:#2D6A4F;--amber:#D4A373;--ink:#16181d;--muted:#6b7280;--line:#e6e8ee;--bg:#f5f6fa;--card:#fff;
  --c-dom:#0D2481;--c-camara:#7c3aed;--c-mpsc:#b45309;--c-tce:#0f766e;--c-prefeitura:#166534}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);line-height:1.45}
header{background:var(--navy);color:#fff;padding:16px 16px 12px;position:relative}
header h1{margin:0;font-size:19px;font-weight:800;padding-right:118px}header .sub{font-size:12px;opacity:.85;margin-top:3px}
header .mesa-link{position:absolute;top:14px;right:14px;color:#fff;font-size:12px;font-weight:800;text-decoration:none;background:rgba(255,255,255,.15);padding:6px 11px;border-radius:999px}
.disc{background:#fff7ed;border-bottom:1px solid #fed7aa;color:#9a3412;font-size:11.5px;padding:7px 16px}
.wrap{max-width:940px;margin:0 auto;padding:12px}
.bar{position:sticky;top:0;z-index:5;background:var(--card);border:1px solid var(--line);border-radius:11px;padding:9px;display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.bar input,.bar select{font:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink)}
.bar input[type=search]{flex:1 1 160px;min-width:130px}
.muted{color:var(--muted);font-size:12px}.small{font-size:11.5px;margin:2px 0 8px}
.count{margin-left:auto;font-size:12px;color:var(--muted)}
.srcs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:7px}
.sb{font:inherit;font-size:12.5px;font-weight:700;padding:7px 11px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--ink);cursor:pointer}
.sb.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.sb b{opacity:.8;margin-left:3px}
.lens{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:9px}
.lb{font:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--ink);cursor:pointer}
.lb.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.lb.cinca.on{background:var(--amber);color:#1c1408;border-color:var(--amber)}
.days{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:9px}
.db{font:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--ink);cursor:pointer}
.db.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.ib{font:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--ink);cursor:pointer}
.ib.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.frec.vago{color:#7a5b12;background:#fdf6e1;border:1px solid #f0e0b0}
.frec.anun{color:#1c1408;background:#fde68a;border:1px solid #f4b400}
.ib.anun.on{background:#f4b400;color:#1c1408;border-color:#f4b400}
.voltar{color:#fff;font-size:12px;text-decoration:none;opacity:.85;display:inline-block;margin-bottom:4px}
.day-sep{font-size:12px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.5px;margin:10px 2px 0;padding-top:7px;border-top:1px dashed var(--line)}
.day-sep:first-child{border-top:none;padding-top:0;margin-top:0}
main{display:flex;flex-direction:column;gap:9px}
.card{background:var(--card);border:1px solid var(--line);border-radius:13px;overflow:hidden}
.face{display:flex;gap:11px;padding:13px}
.score{flex:0 0 auto;width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff;background:var(--green)}
.score.hi{background:var(--red)}.score.mid{background:var(--navy)}.score.lo{background:var(--amber)}
.star{flex:0 0 auto;align-self:flex-start;font-size:20px;line-height:1;width:34px;height:34px;border-radius:9px;border:1px solid var(--line);background:#fff;color:#cbd0db;cursor:pointer;padding:0;transition:transform .08s}
.star:active{transform:scale(.88)}
.star.on{color:#f4b400;border-color:#f4d27a;background:#fffbeb}
.hd{flex:1;min-width:0}
.l1{line-height:1.25;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.src-badge{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;padding:2px 7px;border-radius:999px;color:#fff}
.src-dom{background:var(--c-dom)}.src-camara{background:var(--c-camara)}.src-mpsc{background:var(--c-mpsc)}.src-tce{background:var(--c-tce)}.src-prefeitura{background:var(--c-prefeitura)}
.muni{font-weight:800;font-size:15px}.reg{font-weight:600;color:var(--muted);font-size:12px}
.lens-dot{font-size:13px}
.l2{font-size:13.5px;margin:4px 0;color:#222}
.l3{font-size:13.5px;font-weight:700;color:var(--navy);font-style:italic}
.badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}
.frec{font-size:11px;font-weight:700;border-radius:999px;padding:2px 9px}
.frec.cinca{color:#fff;background:var(--navy)}
details summary{cursor:pointer;list-style:none;font-size:12.5px;font-weight:700;color:var(--navy);padding:9px 13px;border-top:1px solid var(--line);background:#f8f9fc}
summary::-webkit-details-marker{display:none}summary::before{content:"▸ "}details[open]>summary::before{content:"▾ "}
.det{padding:12px 13px}.det .org{font-size:12px;color:var(--muted);margin:2px 0 6px}
.tg{font-size:11px;color:var(--amber);font-weight:700;text-transform:uppercase;margin-bottom:6px}
.why{font-size:13px;color:#333;background:#f8f9fc;border-left:3px solid var(--navy);padding:7px 10px;border-radius:0 8px 8px 0;margin:2px 0 8px}
.why .lab{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;margin-bottom:2px}
.meta{display:flex;gap:7px;flex-wrap:wrap;margin:7px 0}
.pill{font-size:11px;padding:3px 9px;border-radius:999px;background:#eef1f8;color:var(--navy);font-weight:600}
.extra{font-size:12.5px;color:#444;margin:6px 0}
.lab{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:700;margin:8px 0 2px}
.apurar{margin:4px 0;padding-left:18px}.apurar li{font-size:13px;margin:3px 0}
.angulo{font-size:13px;font-style:italic;color:#334;margin:6px 0}
.links{margin-top:9px}.srcl{display:inline-block;font-size:12.5px;font-weight:700;color:#fff;background:var(--navy);padding:7px 13px;border-radius:9px;text-decoration:none}
.srcl.pdf{background:#fff;color:var(--navy);border:1px solid var(--navy);margin-left:6px}
.srcl.integra-btn{border:none;cursor:pointer;font:inherit}
.srcl.integra-btn.on{background:#fff;color:var(--navy);border:1px solid var(--navy)}
.integra-box{display:none;margin-top:8px}.integra-box.open{display:block}
.integra-txt{white-space:pre-wrap;word-break:break-word;font-size:12.5px;line-height:1.5;color:#222;background:#f8f9fc;border:1px solid var(--line);border-left:3px solid var(--navy);border-radius:0 8px 8px 0;padding:10px 12px;max-height:340px;overflow:auto}
.integra-load{font-size:12px;color:var(--muted);padding:8px 2px}
.origs{margin-top:9px;display:flex;gap:14px;flex-wrap:wrap}
.origs a{font-size:11.5px;color:var(--muted);font-weight:700;text-decoration:none}
.origs a:hover{color:var(--navy);text-decoration:underline}
.empty{text-align:center;color:var(--muted);padding:36px 16px}
footer{padding:18px 16px 40px;text-align:center;color:var(--muted);font-size:11px}
</style></head><body>
<header><a class="mesa-link" href="/mesa">📌 Mesa de Pauta ›</a><a class="voltar" href="/radar-civico">‹ Radar Cívico</a><h1>{$titulo}</h1>
<div class="sub">{$sub}</div></header>
<div class="disc">⚖️ Cada item é um <b>FATO</b> público e um <b>LEAD pra apurar</b> — não uma acusação. Nota de prefeitura = versão oficial.</div>
<div class="wrap">
{$srcsHtml}<div class="lens">
  <button type="button" class="lb on" data-tipo="">Tudo</button>
  <button type="button" class="lb" data-tipo="fiscalizacao">🔴 Fiscalização <b>{$s['fisc']}</b></button>
  <button type="button" class="lb" data-tipo="servico">🟢 Serviço <b>{$s['serv']}</b></button>
  <button type="button" class="lb cinca" id="bcinca">🤝 CINCATARINA <b>{$s['cinca']}</b></button>
</div>
<div class="days">
  <button type="button" class="db on" data-day="">Todo período</button>
  <button type="button" class="db" data-day="hoje">Hoje</button>
  <button type="button" class="db" data-day="ontem">Ontem</button>
  <button type="button" class="db" data-day="7">Últimos 7 dias</button>
</div>
<div class="days" id="ints">
  <button type="button" class="ib" data-int="">Todas as cidades</button>
  <button type="button" class="ib" data-int="1">⭐ Cidades de interesse <b>{$s['interesse']}</b></button>
  <button type="button" class="ib anun" id="banun" title="cidades com anunciante ativo — só exibição, não muda score">💰 Só anunciantes <b>{$s['anun']}</b></button>
</div>
<div class="bar">
  <input type="search" id="q" placeholder="🔎 cidade, objeto, órgão, gancho…">
  <select id="ord"><option value="score">Noticiabilidade</option><option value="data">Data</option><option value="muni">Município</option></select>
  <span class="count" id="count"></span>
</div>
<div class="muted small">Une as fontes do radar cívico · a página enche sozinha conforme cada faro termina.</div>
<main id="lista"></main>
</div>
<footer><a href="/radar-civico">‹ voltar ao Radar Cívico</a> · gerado em {$gerado} · Jornal Razão — uso editorial interno</footer>
<script>
const DADOS={$json};
const SEL=new Set({$selJson});
const SRCN={dom:"DOM",camara:"Câmara",mpsc:"MPSC",tce:"TCE",tjsc:"TJSC",prefeitura:"Prefeitura"};
const SRCI={dom:"🧾",camara:"📜",mpsc:"⚖️",tce:"💰",tjsc:"👨‍⚖️",prefeitura:"📣"};
const z2=n=>String(n).padStart(2,"0");
const ymd=d=>d.getFullYear()+"-"+z2(d.getMonth()+1)+"-"+z2(d.getDate());
const _h=new Date();const Y_HOJE=ymd(_h);
const _o=new Date(_h);_o.setDate(_o.getDate()-1);const Y_ONTEM=ymd(_o);
const _7=new Date(_h);_7.setDate(_7.getDate()-6);const Y_7=ymd(_7);
const dayLabel=dp=>{if(!dp)return"sem data";if(dp===Y_HOJE)return"Hoje";if(dp===Y_ONTEM)return"Ontem";const p=String(dp).split("-");return p.length===3?p[2]+"/"+p[1]+"/"+p[0]:dp;};
const fmtD=d=>{if(!d)return"";const p=String(d).split("-");return p.length===3?p[2]+"/"+p[1]:d;};
const esc=s=>(s||"").replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c]));
const cls=s=>s>=80?"hi":s>=60?"mid":s>=40?"":"lo";
let srcSel="",tipoSel="",soCinca=false,soAnun=false,daySel="",intSel="{$intIni}";
function card(d){
  const apurar=(d.apurar||[]).map(b=>'<li>'+esc(b)+'</li>').join("");
  const meta=(d.meta||[]).map(m=>'<span class="pill">'+esc(m)+'</span>').join("");
  const reg=d.regiao?'<span class="reg"> · '+esc(d.regiao)+'</span>':'';
  const hook=d.gancho_curto||d.gancho||"";
  const lens=d.tipo==='fiscalizacao'?'<span class="lens-dot" title="fiscalização">🔴</span>':(d.tipo==='servico'?'<span class="lens-dot" title="serviço/1ª-mão">🟢</span>':'');
  const cinca=d.cinca?'<span class="frec cinca">🏛️ CINCATARINA</span>':'';
  const anun=d.anun?'<span class="frec anun" title="cidade com anunciante ativo — só exibição, não muda o score">💰 anunciante</span>':'';
  const vago=d.vago?'<span class="frec vago" title="objeto não identificado — lead incompleto">⚠️ objeto vago</span>':'';
  return '<div class="card" id="ato-'+d.ato_ref.replace(":","-")+'">'+
    '<div class="face">'+
      '<div class="score '+cls(d.score)+'" title="exibição: '+d.score_x+'">'+d.score+'</div>'+
      '<div class="hd">'+
        '<div class="l1"><span class="src-badge src-'+d.source+'">'+(SRCI[d.source]||"")+' '+esc(SRCN[d.source]||d.source)+'</span>'+lens+'<span class="muni">'+esc(d.municipio||"—")+'</span>'+reg+'</div>'+
        '<div class="l2">'+esc(d.objeto||"")+'</div>'+
        (hook?'<div class="l3">'+esc(hook)+'</div>':'')+
        ((cinca||anun||vago)?'<div class="badges">'+cinca+anun+vago+'</div>':'')+
      '</div>'+
      '<button type="button" class="star'+(SEL.has(d.ato_ref)?' on':'')+'" data-ref="'+esc(d.ato_ref)+'" title="selecionar pra Mesa de Pauta">★</button>'+
    '</div>'+
    '<details><summary>ver detalhes</summary><div class="det">'+
      (d.tipo_gancho?'<div class="tg">'+esc(d.tipo_gancho)+'</div>':'')+
      (d.gancho?'<div class="why"><span class="lab">por que vira pauta</span>'+esc(d.gancho)+'</div>':'')+
      (d.orgao?'<div class="org">'+esc(d.orgao)+'</div>':'')+
      (meta?'<div class="meta">'+meta+'<span class="pill">'+fmtD(d.data_pub)+'</span></div>':'')+
      (d.extra?'<div class="extra">'+esc(d.extra)+'</div>':'')+
      (apurar?'<div class="lab">o que apurar</div><ul class="apurar">'+apurar+'</ul>':'')+
      (d.angulo?'<div class="angulo">Ângulo: '+esc(d.angulo)+'</div>':'')+
      '<div class="links">'+
        '<button type="button" class="srcl integra-btn" data-ref="'+esc(d.ato_ref)+'">📄 Ler a íntegra do ato</button>'+
        '<div class="integra-box"></div>'+
        '<div class="origs">'+
          (d.url_fonte?'<a href="'+esc(d.url_fonte)+'" target="_blank" rel="noopener">ver na fonte ↗</a>':'')+
          (d.url_pdf?'<a href="'+esc(d.url_pdf)+'" target="_blank" rel="noopener">abrir PDF original ↗</a>':'')+
        '</div>'+
      '</div>'+
    '</div></details>'+
  '</div>';
}
function render(){
  const q=document.getElementById("q").value.toLowerCase().trim();
  const ord=document.getElementById("ord").value;
  let arr=DADOS.filter(d=>{
    if(srcSel&&d.source!==srcSel)return false;
    if(tipoSel&&d.tipo!==tipoSel)return false;
    if(soCinca&&!d.cinca)return false;
    if(soAnun&&!d.anun)return false;
    if(intSel&&!d.tier)return false;
    if(daySel){const dp=String(d.data_pub||"");
      if(daySel==="hoje"&&dp!==Y_HOJE)return false;
      if(daySel==="ontem"&&dp!==Y_ONTEM)return false;
      if(daySel==="7"&&(!dp||dp<Y_7))return false;}
    if(q){const h=((d.municipio||"")+" "+(d.regiao||"")+" "+(d.orgao||"")+" "+(d.objeto||"")+" "+(d.gancho_curto||"")+" "+(d.gancho||"")+" "+(d.tipo_gancho||"")+" "+(d.extra||"")).toLowerCase();if(!h.includes(q))return false;}
    return true;});
  arr.sort((a,b)=>{
    if(ord==="data")return(String(b.data_pub||"")).localeCompare(String(a.data_pub||""));
    if(ord==="muni")return(a.municipio||"").localeCompare(b.municipio||"");
    return b.score_x-a.score_x;});
  document.getElementById("count").textContent=arr.length+" itens";
  let html="";
  if(ord==="data"){let lastDay=null;arr.forEach(d=>{const dp=String(d.data_pub||"");
    if(dp!==lastDay){lastDay=dp;html+='<div class="day-sep">'+esc(dayLabel(dp))+'</div>';}html+=card(d);});}
  else{// cara do gol: frescos (48h) primeiro, resto vira Arquivo
    const fresco=arr.filter(d=>d.fresco), velho=arr.filter(d=>!d.fresco);
    html=(fresco.length?'<div class="day-sep">🔥 Últimas 48h</div>'+fresco.map(card).join(""):"")
        +(velho.length?'<div class="day-sep">📁 Arquivo (mais antigos)</div>'+velho.map(card).join(""):"");}
  document.getElementById("lista").innerHTML=arr.length?html:'<div class="empty">Nenhum item bate os filtros.</div>';
}
document.querySelectorAll(".sb").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll(".sb").forEach(x=>x.classList.remove("on"));
  b.classList.add("on");srcSel=b.dataset.src;render();}));
document.querySelectorAll(".lb[data-tipo]").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll(".lb[data-tipo]").forEach(x=>x.classList.remove("on"));
  b.classList.add("on");tipoSel=b.dataset.tipo;render();}));
document.getElementById("bcinca").addEventListener("click",e=>{soCinca=!soCinca;e.currentTarget.classList.toggle("on",soCinca);render();});
document.querySelectorAll(".db").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll(".db").forEach(x=>x.classList.remove("on"));b.classList.add("on");daySel=b.dataset.day;render();}));
document.querySelectorAll("#ints .ib[data-int]").forEach(b=>b.addEventListener("click",()=>{
  document.querySelectorAll("#ints .ib[data-int]").forEach(x=>x.classList.remove("on"));b.classList.add("on");intSel=b.dataset.int;render();}));
document.querySelector('#ints .ib[data-int="'+intSel+'"]').classList.add("on");
// BLOCO 6 — toggle "só anunciantes" (independente do filtro de interesse)
document.getElementById("banun").addEventListener("click",e=>{soAnun=!soAnun;e.currentTarget.classList.toggle("on",soAnun);render();});
["q","ord"].forEach(id=>document.getElementById(id).addEventListener("input",render));
// link pro card vindo do alerta do Telegram (#ato-<source>-<id>): rola, abre e pisca
function jumpHash(){
  if(!location.hash.startsWith("#ato-"))return;
  const el=document.getElementById(location.hash.slice(1));if(!el)return;
  el.scrollIntoView({block:"center"});
  const det=el.querySelector("details");if(det)det.open=true;
  el.style.outline="3px solid #f4b400";el.style.outlineOffset="2px";
  setTimeout(()=>{el.style.outline="";el.style.outlineOffset="";},2600);
}
// ★ selecionar pra Mesa de Pauta (delegação — os cards são re-renderizados)
document.getElementById("lista").addEventListener("click",async e=>{
  // Fase 2 — abrir/fechar a íntegra do ato (lazy)
  const ib=e.target.closest(".integra-btn");
  if(ib){
    const box=ib.parentElement.querySelector(".integra-box");
    if(box.classList.contains("open")){box.classList.remove("open");ib.classList.remove("on");return;}
    box.classList.add("open");ib.classList.add("on");
    if(!box.dataset.loaded){
      box.innerHTML='<div class="integra-load">carregando a íntegra…</div>';
      try{
        const r=await fetch("/radar-civico/ato/"+ib.dataset.ref.replace(":","/"));
        const j=await r.json();
        box.dataset.loaded="1";
        box.innerHTML=j.texto?('<div class="integra-txt">'+esc(j.texto)+'</div>')
          :'<div class="integra-load">(sem texto integral capturado pra este ato — use o original)</div>';
      }catch(_){box.innerHTML='<div class="integra-load">não consegui carregar agora.</div>';}
    }
    return;
  }
  const b=e.target.closest(".star");if(!b)return;
  const ref=b.dataset.ref;const d=DADOS.find(x=>x.ato_ref===ref);if(!d)return;
  if(SEL.has(ref)){window.location.href="/mesa";return;}   // já na fila → abre a Mesa
  b.classList.add("on");
  try{
    const r=await fetch("/mesa/selecionar",{method:"POST",
      headers:{"Content-Type":"application/json","Accept":"application/json"},
      body:JSON.stringify({ato_ref:d.ato_ref,source:d.source,municipio:d.municipio,regiao:d.regiao,
        objeto:d.objeto,gancho_curto:d.gancho_curto||d.gancho,score:d.score,url_fonte:d.url_fonte})});
    if(!r.ok)throw new Error(r.status);
    SEL.add(ref);
  }catch(_){b.classList.remove("on");
    alert("Não consegui salvar na Mesa. Arme o painel uma vez: abra /mesa?key=SUA_CHAVE e depois volte.");}
});
render();
jumpHash();
window.addEventListener("hashchange",jumpHash);
</script>
</body></html>
HTML;
    }
}
