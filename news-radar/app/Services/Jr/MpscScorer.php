<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Process;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Faro MPSC — pontua extratos de instauração de procedimentos do MP pela ótica
 * de um EDITOR (noticiabilidade), reaproveitando o motor (Sonnet, dual-lens
 * 🔴/🟢, driver claude-cli) com PROMPT de lente MPSC. ISOLADO do juiz/Opus.
 *
 * ⚖️ Ética travada: a instauração é FATO público (o MP abriu um procedimento pra
 * APURAR algo — não é condenação). A saída é LEAD ("o MP investiga X"), NUNCA
 * afirmação de culpa de quem quer que seja.
 */
class MpscScorer
{
    private string $modelo;

    public function __construct(?string $modelo = null)
    {
        $this->modelo = $modelo ?: (string) config('mpsc.scoring.modelo', 'claude-sonnet-4-6');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * $itens = [['id'=>, 'tipo_proc'=>, 'comarca'=>, 'orgao'=>, 'partes'=>,
     *           'objeto'=>, 'texto'=>], …]
     *
     * @return array<int,array> por id
     *
     * @throws \RuntimeException
     */
    public function pontuarLote(array $itens): array
    {
        $prompt = $this->montarPrompt($itens);

        $ultimoErro = null;
        for ($t = 1; $t <= 2; $t++) {
            try {
                return $this->parse($this->chamarLlm($prompt), $itens);
            } catch (\Throwable $e) {
                $ultimoErro = $e;
            }
        }

        throw new \RuntimeException('MpscScorer falhou após retry: ' . $ultimoErro->getMessage(), 0, $ultimoErro);
    }

    private function montarPrompt(array $itens): string
    {
        $rotulos = [
            'inquerito_civil' => 'Inquérito Civil',
            'noticia_de_fato' => 'Notícia de Fato',
            'procedimento_preparatorio' => 'Procedimento Preparatório',
            'procedimento_administrativo' => 'Procedimento Administrativo',
            'pa_acompanhamento' => 'PA de Acompanhamento',
        ];
        $lista = '';
        foreach ($itens as $it) {
            $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($it['texto'] ?? ''))), 0, 700);
            $lista .= sprintf(
                "ID %d\nPROCEDIMENTO: %s\nCOMARCA: %s | ÓRGÃO: %s\nPARTES: %s\nOBJETO: %s\nTEXTO: %s\n\n",
                $it['id'],
                $rotulos[$it['tipo_proc']] ?? ($it['tipo_proc'] ?: '(?)'),
                $it['comarca'] ?: '(?)',
                $it['orgao'] ?: '(?)',
                trim((string) ($it['partes'] ?? '')) ?: '(?)',
                trim((string) ($it['objeto'] ?? '')) ?: '(?)',
                $texto ?: '(sem texto)'
            );
        }

        // área de cobertura editorial (config/interesse.php) — interesse local
        $cidades = CidadesInteresse::listaPrompt();

        return <<<PROMPT
Você é um EDITOR de jornalismo investigativo local em Santa Catarina, lendo o Diário Oficial do MINISTÉRIO PÚBLICO (MPSC). Cada item abaixo é um EXTRATO DE INSTAURAÇÃO: o MP abriu um procedimento (inquérito civil, notícia de fato, etc.) pra APURAR algo. Para cada um responda:

  "Um editor de jornal local olharia isto e veria MATÉRIA? Por quê?"

⚖️ ÉTICA (INEGOCIÁVEL): instaurar um procedimento = o MP vai APURAR — NÃO é condenação nem prova de culpa de ninguém (nem do investigado, nem do órgão citado). A saída é sempre LEAD factual: "o MP abriu inquérito pra apurar X". JAMAIS afirme que houve crime/irregularidade. Linguagem: "o MP investiga", "vai apurar", "abriu inquérito sobre".

O que ELEVA a noticiabilidade (interesse público local):
- PODER PÚBLICO no foco: Prefeitura, Câmara, Município, Secretaria, autarquia, fundo, gestor público, serviço público (saúde, hospital, escola, saneamento, meio ambiente, obra).
- TEMA coletivo: improbidade, contrato/licitação, ambiental, consumidor em massa, saúde pública, educação, acessibilidade, patrimônio público.
- RELEVÂNCIA/escala: muitos afetados, valor alto, órgão importante, repercussão.

📍 ÁREA DE COBERTURA DO JORNAL (interesse local): {$cidades}.
Procedimento NESSAS cidades tem interesse local maior (o leitor do jornal mora lá) —
um inquérito mediano em Penha interessa MAIS que um grande em cidade fora da área.
Cidade fora da área precisa de gancho MAIS forte pra passar de 70. NÃO invente
proximidade. A âncora geográfica é a COMARCA: a comarca de Tijucas cobre Tijucas,
Canelinha, São João Batista e Nova Trento.

O que REBAIXA (score baixo, geralmente "neutro"):
- Disputa estritamente PRIVADA/individual sem interesse coletivo (briga de vizinhos, um consumidor isolado), questão de família, arquivamento criminal de rotina.

🔭 DUAS LENTES (campo "tipo"):
- "fiscalizacao" 🔴 = o MP mirando GESTÃO/SERVIÇO PÚBLICO ou tema coletivo relevante (prefeitura, contrato, ambiental, saúde, improbidade). É LEAD pra apurar.
- "servico" 🟢 = procedimento que vira INFORMAÇÃO ÚTIL ao cidadão sem ser denúncia de gestor: defesa de um direito coletivo (fila de cirurgia, vaga em creche, acessibilidade), recomendação/TAC de melhoria de serviço.
- "neutro" = sem ângulo público (privado/individual/rotina).
AMBOS 🔴 e 🟢 recebem score de noticiabilidade. Só "neutro" fica baixo (<40).

Para CADA item responda:
- score_pauta: 0-100 = NOTICIABILIDADE. 80-100 = capa (MP mira prefeitura/escândalo coletivo); 60-79 = boa pauta; 40-59 = fraca; <40 = privado/rotina.
- objeto_limpo: UMA linha clara do que o MP vai apurar, em português jornalístico (ex.: "MP investiga contratação de show sem licitação em X", "MP apura fila de cirurgias no Hospital Y"). Factual, tom de LEAD, sem acusar.
- gancho_curto: hook de ≤8 palavras (ex.: "MP mira prefeitura de X", "Inquérito sobre hospital lotado"). LEAD, nunca acusação.
- gancho: 1 frase — por que vira pauta.
- tipo: "fiscalizacao" | "servico" | "neutro".
- tipo_de_gancho: TEXTO LIVRE curto (ex.: "MP x prefeitura", "saúde pública", "ambiental", "licitação", "consumidor").
- o_que_apurar: array 2-4 bullets — o que checar, quem ouvir, status do procedimento.
- angulo_sugerido: 1 frase — ângulo/título possível.
- flags: array vazio [] (sem eixos fixos aqui).

RESPONDA APENAS com um array JSON válido, um objeto por item, sem markdown:
[{"id": <id>, "score_pauta": <0-100>, "tipo": "fiscalizacao|servico|neutro", "objeto_limpo": "...", "gancho_curto": "...", "gancho": "...", "tipo_de_gancho": "...", "o_que_apurar": ["..."], "angulo_sugerido": "...", "flags": []}]

EXTRATOS:

{$lista}
PROMPT;
    }

    private function chamarLlm(string $prompt): string
    {
        if (! str_starts_with($this->modelo, 'claude')) {
            return $this->chamarOpenai($prompt);
        }

        return $this->chamarClaudeCli($prompt);
    }

    private function chamarOpenai(string $prompt): string
    {
        $params = [
            'model' => $this->modelo,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        // gpt-4*: deterministico; familia gpt-5/o* (reasoning): effort low segura custo.
        if (str_starts_with($this->modelo, 'gpt-4')) {
            $params['temperature'] = 0;
        } else {
            $params['reasoning_effort'] = 'low';
        }
        $r = OpenAI::chat()->create($params);

        return (string) ($r->choices[0]->message->content ?? '');
    }

    private function chamarClaudeCli(string $prompt): string
    {
        $r = Process::timeout(600)->run([
            'claude', '-p', $prompt,
            '--model', $this->modelo,
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

        return (string) ($json['result'] ?? '');
    }

    /** @return array<int,array> por id */
    private function parse(string $texto, array $itens): array
    {
        $texto = trim($texto);
        if (preg_match('/\[.*\]/s', $texto, $m)) {
            $texto = $m[0];
        }
        $arr = json_decode($texto, true);
        if (! is_array($arr)) {
            throw new \RuntimeException('JSON inválido do faro MPSC: ' . mb_substr($texto, 0, 200));
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
            $apurar = $v['o_que_apurar'] ?? [];
            $objLimpo = trim((string) ($v['objeto_limpo'] ?? ''));
            $tipo = mb_strtolower(trim((string) ($v['tipo'] ?? '')));
            $tipo = in_array($tipo, ['fiscalizacao', 'servico', 'neutro'], true) ? $tipo : 'neutro';
            $out[$id] = [
                'score_pauta' => max(0, min(100, (int) ($v['score_pauta'] ?? 0))),
                'tipo' => $tipo,
                'objeto_limpo' => $objLimpo !== '' ? mb_substr($objLimpo, 0, 240) : null,
                'gancho_curto' => mb_substr(trim((string) ($v['gancho_curto'] ?? '')), 0, 120),
                'gancho' => mb_substr(trim((string) ($v['gancho'] ?? '')), 0, 400),
                'tipo_de_gancho' => mb_substr(trim((string) ($v['tipo_de_gancho'] ?? '')), 0, 80),
                'o_que_apurar' => is_array($apurar) ? array_values(array_filter(array_map(fn ($b) => mb_substr(trim((string) $b), 0, 300), $apurar))) : [],
                'angulo_sugerido' => mb_substr(trim((string) ($v['angulo_sugerido'] ?? '')), 0, 400),
                'flags' => [],
            ];
        }

        $faltando = array_diff($idsEsperados, array_keys($out));
        if ($faltando) {
            throw new \RuntimeException('Faro MPSC não devolveu os ids: ' . implode(',', $faltando));
        }

        return $out;
    }
}
