<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Process;

/**
 * Radar de Oportunidades — pontua atos do DOM/SC pela ótica de um EDITOR de
 * jornalismo local (noticiabilidade), NÃO de um auditor de valor.
 *
 * ISOLADO do juiz: prompt próprio, modelo próprio (Sonnet por padrão, trocável
 * por config), driver claude-cli igual ao resto da casa, mas SEM tocar em
 * JuizLlm/jrlink. Custo não é gate (Max).
 *
 * ⚖️ Ética travada no prompt: o ato é FATO; "superfaturado/irregular" é TESE a
 * apurar. A saída é sempre LEAD ("possível pauta / vale apurar"), NUNCA acusação.
 */
class DomScorer
{
    private string $modelo;

    public function __construct(?string $modelo = null)
    {
        $this->modelo = $modelo ?: (string) config('dom.scoring.modelo', 'claude-sonnet-4-6');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * Pontua um LOTE. $itens = [['ato_id'=>int, 'municipio'=>, 'orgao'=>,
     * 'categoria'=>, 'modalidade'=>, 'valor'=>, 'titulo'=>, 'texto'=>], …].
     *
     * @return array<int,array>  por ato_id: score_pauta, gancho, tipo_de_gancho,
     *                           o_que_apurar[], angulo_sugerido, flags[]
     *
     * @throws \RuntimeException se falhar após retry
     */
    public function pontuarLote(array $itens): array
    {
        $prompt = $this->montarPrompt($itens);

        $ultimoErro = null;
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            try {
                $texto = $this->chamarClaudeCli($prompt);

                return $this->parse($texto, $itens);
            } catch (\Throwable $e) {
                $ultimoErro = $e;
            }
        }

        throw new \RuntimeException('DomScorer falhou após retry: ' . $ultimoErro->getMessage(), 0, $ultimoErro);
    }

    private function montarPrompt(array $itens): string
    {
        $lista = '';
        foreach ($itens as $it) {
            $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($it['texto'] ?? ''))), 0, 900);
            $valor = $it['valor'] ? ('R$ ' . number_format((float) $it['valor'], 2, ',', '.')) : '(não detectado)';
            $lista .= sprintf(
                "ID %d\nMUNICÍPIO/ÓRGÃO: %s — %s\nCATEGORIA: %s | MODALIDADE: %s | VALOR DETECTADO: %s\nTÍTULO: %s\nOBJETO (extração heurística, pode estar tosca): %s\nTEXTO: %s\n\n",
                $it['ato_id'],
                $it['municipio'] ?: '(?)',
                $it['orgao'] ?: '(?)',
                $it['categoria'] ?: '(?)',
                $it['modalidade'] ?: '(?)',
                $valor,
                trim((string) ($it['titulo'] ?? '')),
                trim((string) ($it['objeto_limpo'] ?? '')) ?: '(?)',
                $texto ?: '(sem texto)'
            );
        }

        return <<<PROMPT
Você é um EDITOR de jornalismo investigativo local em Santa Catarina, lendo o Diário Oficial dos Municípios à caça de PAUTA. Para cada ato administrativo abaixo (licitação/compra pública de uma prefeitura/câmara/fundo), responda UMA pergunta central:

  "Um editor de jornal local olharia isto e veria MATÉRIA? Por quê?"

Isso é NOTICIABILIDADE / interesse público — NÃO é auditoria de valor. Valor é só UM sinal entre vários e NÃO é requisito: coisa barata pode ser pauta, coisa cara pode ser rotina.

⚖️ ÉTICA (INEGOCIÁVEL): o ato é FATO; "superfaturado", "irregular", "desvio", "fraude" são TESE que só a apuração humana confirma. Você sinaliza um LEAD pra investigar — JAMAIS afirma irregularidade. Linguagem: "possível pauta", "vale apurar", "chama atenção". NUNCA acuse.

EIXOS de noticiabilidade (são SINAIS, não filtros — QUALQUER UM basta; pode flaggar algo fora desta lista se renderia matéria):
1. OBJETO que surpreende/contrasta: entretenimento (show, banda, cachê de artista, festa, fogos, evento), luxo/supérfluo (iPhone, carro, mobília cara), inusitado, ou irônico/incompatível com a realidade da cidade.
2. COMO foi comprado: dispensa / inexigibilidade / emergencial — sobretudo pra coisa que dava pra licitar (show, buffet, publicidade). Fracionamento (várias dispensas pequenas). Aditivos que incham contrato.
3. VALOR — mas RELATIVO/desproporcional ao porte do município, não absoluto.
4. SENSIBILIDADE POLÍTICA: publicidade/propaganda/assessoria de comunicação, gasto perto de eleição, câmara gastando consigo mesma, fornecedor ligado a político/parente.
5. PADRÃO: mesmo fornecedor vencendo sempre; concentração de contratos.
6. INTERESSE LOCAL/HUMANO: algo que mexe direto com o bolso/a vida do cidadão.

INSTRUÇÃO-CHAVE: NÃO filtre pelo óbvio nem exija valor alto. Pense "o que renderia TÍTULO" / "o que o cidadão comentaria no grupo de WhatsApp da cidade". Se um jornalista local levantaria a sobrancelha, FLAGGA. Rotina pura (folha de pagamento, IPTU, nomeação corriqueira, aditivo de prazo sem valor) = score baixo.

🔭 DUAS LENTES (classifique cada ato no campo "tipo") — a noticiabilidade NÃO é só polêmica; serviço de 1ª-mão também é pauta:
- "fiscalizacao" 🔴 = RED FLAG a apurar: dispensa/inexigibilidade pra coisa que dava pra licitar, auto-benefício (câmara/gestor gastando consigo), contrato zumbi/aditivo que incha, conflito de interesse, fornecedor recorrente suspeito, valor desproporcional ao porte. (É LEAD pra investigar, NUNCA acusação.)
- "servico" 🟢 = NOVIDADE positiva ou neutra de interesse do cidadão: lançamento de edital de obra (pavimentação, ponte, escola), concurso/processo seletivo público, investimento/compra que melhora um serviço (nova UBS, ambulância, ônibus, creche), evento cultural legítimo. Serve de informação útil, não de denúncia.
- "neutro" = rotina burocrática/descartável (prazo, republicação técnica, sem ângulo).
AMBOS 🔴 e 🟢 recebem score_pauta de NOTICIABILIDADE (um bom 🟢 pode valer 70). Só "neutro" fica baixo.

ÂNCORAS (mostram a AMPLITUDE — o fio comum é "dinheiro público + algo que chama atenção", NÃO valor):
(a) Câmara de Porto Belo compra iPhones por R$124k — luxo + alto valor + órgão pequeno.
(b) Prefeitura contrata SHOW MUSICAL por DISPENSA de licitação — entretenimento + sem concorrência, MESMO barato.
(c) Gasto inflado com publicidade/assessoria perto de eleição.

Para CADA ato responda:
- score_pauta: 0-100 = NOTICIABILIDADE (não valor). 80-100 = chamaria a capa, apuraria HOJE; 60-79 = boa pauta; 40-59 = talvez, fraco; <40 = rotina burocrática.
- objeto_limpo: UMA linha curta e ESPECÍFICA dizendo O QUE está sendo comprado/contratado/feito (ex.: "Show da banda X na festa do município", "Aquisição de 2 caminhões basculantes", "Reforma da praça central"). LIMPE o boilerplate institucional (CNPJ, "torna público", endereço, nº de processo). Concreto, sem juridiquês. NÃO é acusação.
- gancho_curto: hook de NO MÁXIMO 8 palavras — UMA frase/expressão provocativa que faria alguém parar pra ler (ex.: "Por que comprar isso?", "Show por dispensa de novo", "Caro demais pra cidade pequena?"). Provocativo mas é LEAD/pergunta, NUNCA afirma irregularidade.
- gancho: 1 frase curta — por que isto vira pauta (em tom de LEAD, não acusação).
- tipo: UMA de "fiscalizacao" | "servico" | "neutro" (ver DUAS LENTES acima).
- tipo_de_gancho: TEXTO LIVRE e curto (ex.: "show por dispensa", "luxo em órgão pequeno", "publicidade pré-eleição", "fornecedor recorrente", "edital de obra", "concurso público", "gasto desproporcional"). Não se prenda a categorias fixas.
- o_que_apurar: array de 2-4 bullets curtos — o que checar, que pergunta fazer, quem ouvir.
- angulo_sugerido: 1 frase — o ângulo/título que o jornal poderia perseguir.
- flags: array com os números dos EIXOS acima que bateram (ex.: [1,2]).

RESPONDA APENAS com um array JSON válido, um objeto por ato, sem markdown e sem texto fora do JSON:
[{"id": <id>, "score_pauta": <0-100>, "tipo": "fiscalizacao|servico|neutro", "objeto_limpo": "...", "gancho_curto": "...", "gancho": "...", "tipo_de_gancho": "...", "o_que_apurar": ["...","..."], "angulo_sugerido": "...", "flags": [1,2]}]

ATOS:

{$lista}
PROMPT;
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

    /** @return array<int,array> por ato_id */
    private function parse(string $texto, array $itens): array
    {
        $texto = trim($texto);
        if (preg_match('/\[.*\]/s', $texto, $m)) {
            $texto = $m[0];
        }
        $arr = json_decode($texto, true);
        if (! is_array($arr)) {
            throw new \RuntimeException('JSON inválido do scorer: ' . mb_substr($texto, 0, 200));
        }

        $idsEsperados = array_map(fn ($i) => (int) $i['ato_id'], $itens);
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
            $flags = $v['flags'] ?? [];
            $objLimpo = trim((string) ($v['objeto_limpo'] ?? ''));
            // dual-lens: normaliza pra um dos três rótulos (default neutro)
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
                'flags' => is_array($flags) ? array_values(array_filter(array_map('intval', $flags))) : [],
            ];
        }

        $faltando = array_diff($idsEsperados, array_keys($out));
        if ($faltando) {
            throw new \RuntimeException('Scorer não devolveu os ids: ' . implode(',', $faltando));
        }

        return $out;
    }
}
