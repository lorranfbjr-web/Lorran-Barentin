<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Juiz LLM da Fase 2 — julga LOTES de pautas (título + lead) e devolve o
 * veredito editorial em JSON estrito por item:
 *   {id, escopo, eh_pauta, tipo_gancho, cidade, score_editorial, motivo}
 *
 * Drivers:
 *  - openai: MESMO cliente do enriquecimento NewsRadar (OpenAI::chat). Pronto
 *    pra quando houver OPENAI_API_KEY real (a atual é noop de smoke test).
 *  - claude-cli: `claude -p` headless com a assinatura local (Claude Max),
 *    modelo barato (Haiku). É o que funciona HOJE nesta máquina.
 *  - auto: openai se a chave parecer real; senão claude-cli.
 *
 * Toda chamada é logada em jr_juiz_log (modelo, tokens, custo, duração) no
 * padrão de news_item_ai_logs.
 */
class JuizLlm
{
    public const ESCOPOS = ['local', 'regional', 'nacional_localizado', 'nacional'];

    public const GANCHOS = [
        'emocao', 'curiosidade', 'indignacao', 'identidade_sc', 'feel_good',
        'escala', 'conquista_superacao', 'servico', 'solidariedade', 'vaquinha', 'nenhum',
    ];

    private array $cfg;

    private string $driver;

    public function __construct(?array $cfg = null, ?string $driver = null)
    {
        $this->cfg = $cfg ?? config('jrlink.juiz');
        $this->driver = $this->resolveDriver($driver ?: ($this->cfg['driver'] ?? 'auto'));
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function modelo(): string
    {
        return $this->driver === 'openai'
            ? (string) $this->cfg['modelo_openai']
            : (string) $this->cfg['modelo_claude'];
    }

    /**
     * Julga um lote. $itens = [['id' => int, 'titulo' => string, 'lead' => string], …].
     *
     * @return array<int,array>  vereditos validados, indexados pelo id do item
     *
     * @throws \RuntimeException se a chamada ou o parse falharem (após retry)
     */
    public function julgarLote(array $itens): array
    {
        $prompt = $this->montarPrompt($itens);

        $ultimoErro = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $t0 = microtime(true);
            try {
                [$texto, $inTok, $outTok, $custo] = $this->driver === 'openai'
                    ? $this->chamarOpenai($prompt)
                    : $this->chamarClaudeCli($prompt);

                $vereditos = $this->parse($texto, $itens);

                $this->log('success', $attempt, count($itens), $inTok, $outTok, $custo, null, $t0);

                return $vereditos;
            } catch (\Throwable $e) {
                $ultimoErro = $e;
                $this->log('error', $attempt, count($itens), null, null, null, $e->getMessage(), $t0);
            }
        }

        throw new \RuntimeException('Juiz LLM falhou após retry: ' . $ultimoErro->getMessage(), 0, $ultimoErro);
    }

    // ───────────────────────── prompt ─────────────────────────

    private function montarPrompt(array $itens): string
    {
        $lista = '';
        foreach ($itens as $it) {
            $lead = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $it['lead'])), 0, 280);
            $lista .= sprintf("ID %d\nTÍTULO: %s\nLEAD: %s\n\n", $it['id'], trim((string) $it['titulo']), $lead ?: '(sem lead)');
        }

        // Few-shot do feedback humano: '' quando desligado/sem votos — e aí o
        // prompt fica byte a byte idêntico ao atual (interpolação vazia).
        $calibracao = $this->blocoCalibracao();

        return <<<PROMPT
Você é o editor-chefe do Jornal Razão, jornal regional de Tijucas/SC que cobre o litoral e o Vale do Itajaí em Santa Catarina. Julgue cada pauta abaixo.

REGRAS DO VEREDITO:
- escopo: "local" (uma cidade da região), "regional" (SC/região), "nacional_localizado" (assunto nacional COM ângulo local real no título), "nacional" (sem ângulo local).
- Notícia nacional sem ângulo local real NO TÍTULO (Enem, Lula, ONU, decisões da UE, futebol nacional, loteria) = escopo "nacional" e score baixo.
- eh_pauta=false para: SEO/listicle, coluna/opinião, horóscopo, home institucional, aniversariantes do dia, datas comemorativas, conteúdo requentado sem fato novo.
- SEO-washing é SEMPRE eh_pauta=false, mesmo que o fato por trás seja real: título que reconta cobertura alheia em formato de busca — "Como foi…", "O que aconteceu com…", "Tudo o que se sabe sobre…", "O que se sabe…", "Entenda…", "Veja como…", receitas, listas.
- TEMPERATURA/SCORE — a régua é UMA pergunta: "isto seria um post do @jornalrazao?" (DNA real do perfil, engajamento medido em COMENTÁRIOS):
  · O que MAIS engaja (mediana real): política local com emoção/indignação (1648) > flagrante policial COM NARRATIVA (798) > viral/insólito (757) > luto/comoção (686) > superação com nome e história (583) > acidente/resgate com drama (527).
  · O que MENOS engaja e o perfil quase não posta: evento/agenda cultural (150), economia/negócios institucional (141), clima rotineiro (211), serviço/utilidade (265), institucional de prefeitura (313).
  · Flagrante/ocorrência SÓ esquenta com narrativa ou insólito — boletim burocrático (corte de cabos, apreensão sem história) é frio mesmo sendo crime.
  · Evento institucional fofo de prefeitura/órgão (casamento coletivo, inauguração protocolar, campanha oficial) NÃO é quente (score < 60), salvo cobertura múltipla independente de portais.
- EXEMPLOS REAIS do perfil com ALTO engajamento (a cara do quente 80-100):
  · "Casal de pastores de Joinville manteve mulher em cárcere por mais de um ano" (11,4 mil comentários)
  · "Ladrão invadiu casa em Chapecó e teve uma surpresa nada agradável" (10,9 mil)
  · "Por mais de um ano, ela dormiu de chupeta e foi cuidada como bebê" (10,8 mil)
  · "Pescadores choraram na vigia em Quatro Ilhas com a volta da pesca da tainha" (10,5 mil)
  · "Caminhada de uma jovem de 20 anos virou pânico" (9,5 mil)
  · "Padre catarinense viraliza ao pedir oração por 'vadios e preguiçosos'" (7,3 mil)
  · "Ligação 'apavorada' fez Lula reabrir a pesca da tainha" (7,6 mil)
  · "Corretor de luxo em Dubai e companheira investigados por golpe milionário" (6,3 mil)
- EXEMPLOS com BAIXO engajamento / que o perfil evita (frio, score < 40):
  · "Encontro de motos gratuito neste sábado em Tijucas" (6 comentários — agenda)
  · "Blumenau dá passo que famílias esperavam: Vila da casa própria" (8 — institucional)
  · "Programa Estrada Boa Rural segue em expansão" (26 — release de governo)
  · "Loteamento de acesso controlado chega à Grande Florianópolis" (8 — negócio/publi)
  · "Caminhão caçamba atinge dois carros e invade residência" (14 — boletim sem história)
- tipo_gancho: um de emocao|curiosidade|indignacao|identidade_sc|feel_good|escala|conquista_superacao|servico|solidariedade|vaquinha|nenhum.
- Campanha de solidariedade/vaquinha: tipo_gancho "solidariedade" ou "vaquinha" (vai pra fila humana, nunca quente automático).
- cidade: cidade principal da pauta ou null.
- score_editorial: 0-100 calibrado pelo DNA acima — 80-100 = postaria HOJE com cara de capa (história forte, nome, drama, indignação ou insólito); 60-79 = postável; 40-59 = fraco/talvez; <40 = não parece post do perfil. USE A ESCALA TODA, não sature.
- motivo: 1 frase curta justificando.

{$calibracao}RESPONDA APENAS com um array JSON válido, um objeto por pauta, sem markdown e sem texto fora do JSON:
[{"id": <id>, "escopo": "...", "eh_pauta": true|false, "tipo_gancho": "...", "cidade": "..."|null, "score_editorial": <0-100>, "motivo": "..."}]

PAUTAS:

{$lista}
PROMPT;
    }

    /**
     * Chamada genérica de UM prompt esperando array JSON na resposta (usada por
     * comandos auxiliares — DNA do Instagram etc.). NÃO toca no prompt do juiz.
     * Loga em jr_juiz_log com a operation dada. Retorna [] em falha.
     */
    public function completarJson(string $prompt, string $operation, int $itens = 0): array
    {
        $t0 = microtime(true);
        try {
            [$texto, $in, $out, $custo] = $this->driver === 'openai'
                ? $this->chamarOpenai($prompt)
                : $this->chamarClaudeCli($prompt);
            $this->log('success', 1, $itens, $in, $out, $custo, null, $t0, $operation);
            if (preg_match('/\[.*\]/s', $texto, $m)) {
                $texto = $m[0];
            }
            $arr = json_decode($texto, true);

            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) {
            $this->log('error', 1, $itens, null, null, null, $e->getMessage(), $t0, $operation);

            return [];
        }
    }

    /**
     * Merge assistido: "mesmo evento? sim/não" em LOTE para pares de títulos
     * limítrofes do clustering. Prompt PRÓPRIO e separado — o prompt do juiz
     * (montarPrompt) não muda. Retorna [n => bool] por par.
     *
     * @param  array<int,array{a:string,b:string}>  $pares
     */
    public function julgarMesmoEvento(array $pares): array
    {
        if (! $pares) {
            return [];
        }
        $lista = '';
        foreach ($pares as $n => $p) {
            $lista .= sprintf("PAR %d\nA: %s\nB: %s\n\n", $n, trim($p['a']), trim($p['b']));
        }
        $prompt = <<<PROMPT
Você verifica DEDUPLICAÇÃO de notícias regionais de Santa Catarina. Para cada par de títulos abaixo, responda se os dois cobrem o MESMO EVENTO (mesmo fato, mesmas pessoas/lugar — reformulação de manchete conta como mesmo evento; fatos parecidos em lugares/dias diferentes NÃO). Título genérico sem fato identificável (ex.: "Nota de Pesar", "Plantão de notícias") NUNCA é mesmo evento — responda false.

RESPONDA APENAS com um array JSON, sem texto fora dele:
[{"par": <n>, "mesmo": true|false}]

PARES:

{$lista}
PROMPT;

        $t0 = microtime(true);
        try {
            [$texto, $in, $out, $custo] = $this->driver === 'openai'
                ? $this->chamarOpenai($prompt)
                : $this->chamarClaudeCli($prompt);
            $this->log('success', 1, count($pares), $in, $out, $custo, null, $t0, 'cluster_merge');

            if (preg_match('/\[.*\]/s', $texto, $m)) {
                $texto = $m[0];
            }
            $arr = json_decode($texto, true);
            $res = [];
            foreach (is_array($arr) ? $arr : [] as $v) {
                if (isset($v['par'])) {
                    $res[(int) $v['par']] = (bool) ($v['mesmo'] ?? false);
                }
            }

            return $res;
        } catch (\Throwable $e) {
            $this->log('error', 1, count($pares), null, null, null, $e->getMessage(), $t0, 'cluster_merge');

            return []; // merge é otimização — falhou, segue sem fundir
        }
    }

    /**
     * Bloco "calibração do editor" (few-shot do feedback humano). Retorna ''
     * quando a flag está OFF ou há menos votos que o mínimo. Seleção: maiores
     * divergências juiz×humano primeiro, garantindo ao menos 1 exemplo por
     * faixa disponível e cobertura dos ganchos mais votados. Só título +
     * cidade + gancho + faixa — sem texto longo.
     */
    private function blocoCalibracao(): string
    {
        $fs = $this->cfg['fewshot'] ?? [];
        if (! ($fs['enabled'] ?? false)) {
            return '';
        }

        $votos = DB::table('jr_pauta_feedback as f')
            ->join('jr_link_extracao as e', 'e.id', '=', 'f.jr_link_extracao_id')
            ->whereNotNull('f.score_juiz_na_hora')
            ->get(['f.faixa', 'f.ponto_medio', 'f.score_juiz_na_hora', 'f.gancho_na_hora', 'e.titulo', 'e.cidade_llm']);

        if ($votos->count() < (int) ($fs['min_votos'] ?? 30)) {
            return '';
        }

        $max = (int) ($fs['max_exemplos'] ?? 12);
        $ordenados = $votos->sortByDesc(fn ($v) => abs((int) $v->score_juiz_na_hora - (int) $v->ponto_medio))->values();

        // Top divergências + garantia de 1 por faixa + ganchos mais frequentes.
        $escolhidos = $ordenados->take($max)->collect();
        foreach (['baixa', 'media', 'alta'] as $faixa) {
            if (! $escolhidos->contains('faixa', $faixa) && ($cand = $ordenados->firstWhere('faixa', $faixa))) {
                $escolhidos->pop();
                $escolhidos->push($cand);
            }
        }
        $ganchosTop = $votos->groupBy('gancho_na_hora')->map->count()->sortDesc()->take(3)->keys();
        foreach ($ganchosTop as $g) {
            if ($g && ! $escolhidos->contains('gancho_na_hora', $g) && ($cand = $ordenados->firstWhere('gancho_na_hora', $g))) {
                $escolhidos->pop();
                $escolhidos->push($cand);
            }
        }

        $rotulo = ['baixa' => 'FRACA (10-30)', 'media' => 'MEDIANA (30-60)', 'alta' => 'FORTE (60-100)'];
        $linhas = $escolhidos->take($max)->map(fn ($v) => sprintf('- "%s"%s%s → o editor avaliou: %s',
            mb_strimwidth(trim((string) $v->titulo), 0, 110),
            $v->cidade_llm ? ' (' . $v->cidade_llm . ')' : '',
            $v->gancho_na_hora && $v->gancho_na_hora !== 'nenhum' ? ' [gancho ' . $v->gancho_na_hora . ']' : '',
            $rotulo[$v->faixa] ?? $v->faixa))->implode("\n");

        return "CALIBRAÇÃO DO EDITOR (avaliações reais do dono do jornal — alinhe sua régua a elas):\n{$linhas}\n\n";
    }

    // ───────────────────────── drivers ─────────────────────────

    /** @return array{0:string,1:?int,2:?int,3:?float} [texto, in_tokens, out_tokens, custo_usd] */
    private function chamarOpenai(string $prompt): array
    {
        $response = OpenAI::chat()->create([
            'model' => $this->cfg['modelo_openai'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
        ]);

        $texto = (string) ($response->choices[0]->message->content ?? '');
        $in = $response->usage->promptTokens ?? null;
        $out = $response->usage->completionTokens ?? null;
        // gpt-4o-mini: $0.15/M in, $0.60/M out (referência; ajustar se trocar o modelo).
        $custo = ($in !== null && $out !== null) ? ($in * 0.15 + $out * 0.60) / 1_000_000 : null;

        return [$texto, $in, $out, $custo];
    }

    /** @return array{0:string,1:?int,2:?int,3:?float} */
    private function chamarClaudeCli(string $prompt): array
    {
        $r = Process::timeout(180)->run([
            'claude', '-p', $prompt,
            '--model', $this->cfg['modelo_claude'],
            '--output-format', 'json',
            '--max-turns', '1',
        ]);

        if (! $r->successful()) {
            throw new \RuntimeException('claude-cli exit ' . $r->exitCode() . ': ' . mb_substr($r->errorOutput(), 0, 300));
        }

        $json = json_decode(trim($r->output()), true);
        if (! is_array($json) || ($json['is_error'] ?? false)) {
            throw new \RuntimeException('claude-cli retorno inválido: ' . mb_substr($r->output(), 0, 300));
        }

        $uso = $json['usage'] ?? [];
        $in = isset($uso['input_tokens'])
            ? (int) $uso['input_tokens'] + (int) ($uso['cache_creation_input_tokens'] ?? 0) + (int) ($uso['cache_read_input_tokens'] ?? 0)
            : null;

        return [
            (string) ($json['result'] ?? ''),
            $in,
            isset($uso['output_tokens']) ? (int) $uso['output_tokens'] : null,
            isset($json['total_cost_usd']) ? (float) $json['total_cost_usd'] : null,
        ];
    }

    // ───────────────────────── parse / validação ─────────────────────────

    /** @return array<int,array> vereditos por id */
    private function parse(string $texto, array $itens): array
    {
        $texto = trim($texto);
        // tolera cerca de markdown e texto em volta — pega o primeiro array JSON.
        if (preg_match('/\[.*\]/s', $texto, $m)) {
            $texto = $m[0];
        }
        $arr = json_decode($texto, true);
        if (! is_array($arr)) {
            throw new \RuntimeException('JSON inválido do juiz: ' . mb_substr($texto, 0, 200));
        }
        // openai json_object mode pode embrulhar em {"pautas": [...]}.
        if (array_keys($arr) !== range(0, count($arr) - 1)) {
            $arr = collect($arr)->first(fn ($v) => is_array($v)) ?? [];
        }

        $idsEsperados = array_map(fn ($i) => (int) $i['id'], $itens);
        $out = [];
        foreach ($arr as $v) {
            if (! is_array($v) || ! isset($v['id'])) {
                continue;
            }
            $id = (int) $v['id'];
            if (! in_array($id, $idsEsperados, true)) {
                continue;
            }
            $escopo = strtolower(trim((string) ($v['escopo'] ?? '')));
            $gancho = strtolower(trim((string) ($v['tipo_gancho'] ?? 'nenhum')));
            $out[$id] = [
                'escopo' => in_array($escopo, self::ESCOPOS, true) ? $escopo : 'nacional',
                'eh_pauta' => (bool) ($v['eh_pauta'] ?? false),
                'tipo_gancho' => in_array($gancho, self::GANCHOS, true) ? $gancho : 'nenhum',
                'cidade' => isset($v['cidade']) && $v['cidade'] !== '' ? mb_substr((string) $v['cidade'], 0, 80) : null,
                'score_llm' => max(0, min(100, (int) ($v['score_editorial'] ?? 0))),
                'motivo' => mb_substr(trim((string) ($v['motivo'] ?? '')), 0, 400),
            ];
        }

        $faltando = array_diff($idsEsperados, array_keys($out));
        if ($faltando) {
            throw new \RuntimeException('Juiz não devolveu os ids: ' . implode(',', $faltando));
        }

        return $out;
    }

    // ───────────────────────── infra ─────────────────────────

    private function resolveDriver(string $driver): string
    {
        if (in_array($driver, ['openai', 'claude-cli'], true)) {
            return $driver;
        }
        $key = (string) env('OPENAI_API_KEY', '');
        $keyReal = $key !== '' && ! preg_match('/noop|smoke|test|placeholder|xxx/i', $key);

        return $keyReal ? 'openai' : 'claude-cli';
    }

    private function log(string $status, int $attempt, int $itens, ?int $in, ?int $out, ?float $custo, ?string $erro, float $t0, string $operation = 'juiz_lote'): void
    {
        DB::table('jr_juiz_log')->insert([
            'operation' => $operation,
            'model' => $this->modelo(),
            'status' => $status,
            'attempt' => $attempt,
            'itens' => $itens,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'custo_usd' => $custo,
            'error_message' => $erro,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
