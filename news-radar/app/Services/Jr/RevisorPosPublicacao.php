<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * PEÇA 4 — agente revisor pós-post.
 *
 * Depois que o draft é criado (texto + foto), um SEGUNDO Opus relê a matéria
 * REAL (via REST) e audita contra o padrão JR, comparando com o release
 * original da captura. Devolve, em JSON estruturado:
 *  - marcador vazado ([COMMENT], placeholder) no corpo VISÍVEL
 *  - crédito da foto presente (quando há foto destacada)
 *  - atribuição à fonte ("segundo a prefeitura", "conforme")
 *  - invenção: dado/numero/nome/data/fala que NÃO está no release
 *  - categoria/editoria coerente com o conteúdo
 *  - foto destacada coerente com o texto
 *  - começo-meio-fim / status quando cabível
 *
 * O revisor NÃO reescreve fato, não muda número/nome/data, não troca foto. Tudo
 * que for conteúdo editorial vira FLAG pra humano. Só auto-fix seguro e óbvio
 * (remover linha de marcador vazado) é aplicado pelo orquestrador.
 *
 * Mesmo driver claude-cli/Opus do resto do pipeline. Loga custo.
 */
class RevisorPosPublicacao
{
    private string $modelo;

    public function __construct()
    {
        $this->modelo = (string) (config('jrlink.modelos.revisor')
            ?? config('jrlink.modelos.juiz')
            ?? 'claude-opus-4-8');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * Audita um draft. $draft = corpo visível + metadados já lidos do WP.
     *
     * @param  array{titulo:string, corpo_visivel:string, editoria:string, tem_foto:bool, foto_caption:string}  $draft
     * @return array{veredito:string, pendencias:array, achados:array, custo:?float, modelo:string, ok:bool, duration_ms:int}
     */
    public function auditar(array $draft, string $releaseOriginal): array
    {
        $prompt = $this->montarPrompt($draft, $releaseOriginal);

        $t0 = microtime(true);
        try {
            [$saida, $custo] = $this->chamar($prompt);
            $j = $this->parse($saida);
            $dur = (int) round((microtime(true) - $t0) * 1000);
            $this->log('success', $custo, null, $dur);

            $pend = array_values(array_filter(array_map('trim', (array) ($j['pendencias'] ?? []))));
            $veredito = (count($pend) === 0 && ($j['veredito'] ?? '') !== 'revisar') ? 'ok' : 'revisar';

            return [
                'veredito' => $veredito,
                'pendencias' => $pend,
                'achados' => $j['achados'] ?? $j,
                'custo' => $custo,
                'modelo' => $this->modelo,
                'duration_ms' => $dur,
                'ok' => true,
            ];
        } catch (\Throwable $e) {
            $dur = (int) round((microtime(true) - $t0) * 1000);
            $this->log('error', null, $e->getMessage(), $dur);

            return [
                'veredito' => 'revisar', // falha do revisor = manda pra humano (conservador)
                'pendencias' => ['revisor falhou: ' . $e->getMessage()],
                'achados' => [], 'custo' => null, 'modelo' => $this->modelo,
                'duration_ms' => $dur, 'ok' => false,
            ];
        }
    }

    private function montarPrompt(array $draft, string $release): string
    {
        $release = mb_substr(trim($release), 0, 4000);
        $corpo = mb_substr(trim($draft['corpo_visivel']), 0, 4000);
        $fotoInfo = $draft['tem_foto']
            ? ('SIM — legenda/crédito da foto: "' . ($draft['foto_caption'] ?: '(vazio)') . '"')
            : 'NÃO há foto destacada';

        return <<<PROMPT
Você é o editor-revisor do Jornal Razão (jornal regional de SC). Acabei de gerar automaticamente um RASCUNHO a partir de um release oficial. Releia a matéria publicada e AUDITE contra o padrão do jornal, comparando SEMPRE com o release original. Seu trabalho é PEGAR problema, não elogiar.

PADRÃO JR (o que checar):
- FATO vs VERSÃO: afirmação da fonte tem que estar ATRIBUÍDA ("segundo a prefeitura", "de acordo com o órgão", "conforme o comunicado"). Cravar como fato absoluto algo que é versão da prefeitura é erro.
- INVENÇÃO: a matéria NÃO pode conter número, nome, data, cargo ou fala que NÃO esteja no release. Qualquer dado a mais que não dá pra rastrear no release = pendência grave.
- MARCADOR VAZADO: não pode ter [COMMENT], [TODO], {{placeholder}}, colchetes de instrução ou qualquer resíduo de template no corpo VISÍVEL. (Comentários HTML <!-- ... --> NÃO contam, são notas internas.)
- CRÉDITO DA FOTO: se há foto destacada, o crédito tem que estar presente (na legenda da foto e/ou no corpo). Foto oficial sem crédito = pendência.
- CATEGORIA/EDITORIA: a editoria classificada bate com o conteúdo?
- FOTO×TEXTO: a foto destacada (pela legenda/crédito) tem relação plausível com o assunto da matéria?
- ESTRUTURA: tem lead (o quê/quem/quando/onde) e desfecho/serviço/próximos passos quando cabível?
- TERMINOLOGIA SC: termos corretos (ex.: "Polícia Científica", não "perícia genérica"; nomes de órgãos certos).

RELEASE ORIGINAL (verdade-base):
\"\"\"
{$release}
\"\"\"

MATÉRIA PUBLICADA:
TÍTULO: {$draft['titulo']}
EDITORIA CLASSIFICADA: {$draft['editoria']}
FOTO DESTACADA: {$fotoInfo}
CORPO:
\"\"\"
{$corpo}
\"\"\"

RESPONDA APENAS com UM objeto JSON válido, sem markdown e sem texto fora dele:
{"veredito":"ok"|"revisar","achados":{"atribuicao_ok":true|false,"invencao":true|false,"invencao_itens":["..."],"marcador_vazado":true|false,"marcador_itens":["..."],"credito_foto_ok":true|false,"categoria_ok":true|false,"foto_relacionada":true|false,"estrutura_ok":true|false},"pendencias":["frase curta por problema encontrado — vazio se nada"]}
PROMPT;
    }

    /** @return array{0:string,1:?float} */
    private function chamar(string $prompt): array
    {
        $r = Process::timeout(600)->run([
            'claude', '-p', $prompt,
            '--model', $this->modelo,
            '--output-format', 'json',
            '--max-turns', '1',
        ]);

        if (! $r->successful()) {
            throw new \RuntimeException('claude-cli revisor exit ' . $r->exitCode() . ': ' . mb_substr($r->errorOutput(), 0, 300));
        }
        $json = json_decode(trim($r->output()), true);
        if (! is_array($json) || ($json['is_error'] ?? false)) {
            throw new \RuntimeException('claude-cli revisor retorno inválido: ' . mb_substr($r->output(), 0, 300));
        }

        return [(string) ($json['result'] ?? ''), isset($json['total_cost_usd']) ? (float) $json['total_cost_usd'] : null];
    }

    private function parse(string $texto): array
    {
        $texto = trim($texto);
        if (preg_match('/\{.*\}/s', $texto, $m)) {
            $texto = $m[0];
        }
        $arr = json_decode($texto, true);
        if (! is_array($arr)) {
            throw new \RuntimeException('JSON inválido do revisor: ' . mb_substr($texto, 0, 200));
        }

        return $arr;
    }

    private function log(string $status, ?float $custo, ?string $erro, int $dur): void
    {
        DB::table('jr_juiz_log')->insert([
            'operation' => 'revisor_pos_post',
            'model' => $this->modelo,
            'status' => $status,
            'attempt' => 1,
            'itens' => 1,
            'input_tokens' => null,
            'output_tokens' => null,
            'custo_usd' => $custo,
            'error_message' => $erro,
            'duration_ms' => $dur,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
