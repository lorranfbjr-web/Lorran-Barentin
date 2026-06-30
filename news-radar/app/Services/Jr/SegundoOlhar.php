<?php

namespace App\Services\Jr;

use OpenAI\Laravel\Facades\OpenAI;

/**
 * SEGUNDO OLHAR — 2º faro com OpenAI (gpt-4o-mini), CEGO: o GPT NÃO vê o veredito
 * do Sonnet, julga noticiabilidade do zero. Usado só nos CANDIDATOS A PAUTA pra
 * dar diversidade de julgamento (concordam=confiança, divergem=revisar).
 *
 * Fail-closed: sem chave OpenAI real (sk-noop/test/placeholder), disponivel()
 * devolve false e nada é chamado. ISOLADO: não toca o score_pauta do Sonnet.
 *
 * ⚖️ Ética igual ao faro: o ato/proposição/instauração é FATO; a saída é LEAD
 * ("vale apurar / vira matéria?"), NUNCA acusação de irregularidade.
 */
class SegundoOlhar
{
    private string $modelo;

    public function __construct(?string $modelo = null)
    {
        $this->modelo = $modelo ?: (string) config('segundo_olhar.modelo', 'gpt-4o-mini');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /** Chave OpenAI real configurada? (mesma regra anti-placeholder do JuizLlm). */
    public function disponivel(): bool
    {
        $key = (string) config('openai.api_key', '');

        return $key !== '' && ! preg_match('/noop|smoke|test|placeholder|xxx/i', $key);
    }

    /**
     * Opina (CEGO) sobre um lote de candidatos. $contexto = frase curta que diz
     * que TIPO de documento é (ex.: "um ato de compra pública do Diário Oficial
     * dos Municípios de SC"). $itens = [['id'=>int,'ente'=>string,'titulo'=>string,
     * 'texto'=>string], …]. Não recebe o score do Sonnet — julgamento independente.
     *
     * @return array<int,array{eh_pauta:bool,score:int,motivo:string}>  por id
     *
     * @throws \RuntimeException se falhar após retry
     */
    public function opinarLote(array $itens, string $contexto): array
    {
        $prompt = $this->montarPrompt($itens, $contexto);

        $ultimoErro = null;
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            try {
                $texto = $this->chamarOpenai($prompt);

                return $this->parse($texto, $itens);
            } catch (\Throwable $e) {
                $ultimoErro = $e;
            }
        }

        throw new \RuntimeException('SegundoOlhar falhou após retry: ' . $ultimoErro->getMessage(), 0, $ultimoErro);
    }

    private function montarPrompt(array $itens, string $contexto): string
    {
        $lista = '';
        foreach ($itens as $it) {
            $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($it['texto'] ?? ''))), 0, 700);
            $lista .= sprintf(
                "ID %d\nENTE: %s\nTÍTULO: %s\nCONTEÚDO: %s\n\n",
                (int) $it['id'],
                trim((string) ($it['ente'] ?? '(?)')),
                trim((string) ($it['titulo'] ?? '')),
                $texto ?: '(sem texto)'
            );
        }

        return <<<PROMPT
Você é um EDITOR de jornalismo local em Santa Catarina avaliando, item a item, se cada documento abaixo VIRARIA MATÉRIA. Cada item é {$contexto}.

Responda UMA pergunta por item: "um editor de jornal local olharia isto e veria PAUTA de interesse público? Por quê?" — NOTICIABILIDADE, não auditoria de valor. Objeto que surpreende/contrasta, gasto desproporcional ao porte da cidade, dispensa/inexigibilidade pra coisa que dava pra licitar, sensibilidade política (publicidade, autobenefício), serviço de 1ª-mão que interessa ao cidadão (obra, concurso, nova UBS) — qualquer um basta. Rotina burocrática pura = não.

⚖️ ÉTICA: o documento é FATO; "irregular/superfaturado" é TESE a apurar. Você sinaliza um LEAD pra investigar, JAMAIS afirma irregularidade. Pense "o que renderia título" / "o que o cidadão comentaria no grupo de WhatsApp da cidade".

Para CADA item:
- eh_pauta: true se um jornalista local levantaria a sobrancelha; false se é rotina descartável.
- score: 0-100 de NOTICIABILIDADE (não valor). 80-100 = capa hoje; 60-79 = boa pauta; 40-59 = talvez/fraco; <40 = rotina.
- motivo: 1 frase curta (máx 200 caracteres) em tom de LEAD, sem acusar.

RESPONDA APENAS com JSON válido, sem markdown, no formato:
{"itens": [{"id": <id>, "eh_pauta": <true|false>, "score": <0-100>, "motivo": "..."}]}

ITENS:

{$lista}
PROMPT;
    }

    private function chamarOpenai(string $prompt): string
    {
        $r = OpenAI::chat()->create([
            'model' => $this->modelo,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
        ]);

        return (string) ($r->choices[0]->message->content ?? '');
    }

    /** @return array<int,array{eh_pauta:bool,score:int,motivo:string}> por id */
    private function parse(string $texto, array $itens): array
    {
        $texto = trim($texto);
        $arr = json_decode($texto, true);
        // aceita {"itens":[…]} ou um array nu […]
        $linhas = $arr['itens'] ?? (is_array($arr) ? $arr : null);
        if (! is_array($linhas)) {
            throw new \RuntimeException('JSON inválido do segundo olhar: ' . mb_substr($texto, 0, 200));
        }

        $idsEsperados = array_map(fn ($i) => (int) $i['id'], $itens);
        $out = [];
        foreach ($linhas as $v) {
            if (! is_array($v) || ! isset($v['id'])) {
                continue;
            }
            $id = (int) $v['id'];
            if (! in_array($id, $idsEsperados, true)) {
                continue;
            }
            $out[$id] = [
                'eh_pauta' => (bool) ($v['eh_pauta'] ?? false),
                'score' => max(0, min(100, (int) ($v['score'] ?? 0))),
                'motivo' => mb_substr(trim((string) ($v['motivo'] ?? '')), 0, 240),
            ];
        }

        $faltando = array_diff($idsEsperados, array_keys($out));
        if ($faltando) {
            throw new \RuntimeException('Segundo olhar não devolveu os ids: ' . implode(',', $faltando));
        }

        return $out;
    }
}
