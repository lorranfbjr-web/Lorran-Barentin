<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Process;

/**
 * Faro CÂMARA — pontua proposições legislativas pela ótica de um EDITOR de
 * jornalismo local (noticiabilidade), reaproveitando o motor do DomScorer
 * (Sonnet, dual-lens 🔴/🟢, driver claude-cli) com PROMPT de lente CÂMARA.
 *
 * ISOLADO do juiz/Opus: prompt e modelo próprios (Sonnet, trocável por config).
 *
 * ⚖️ Ética travada: a proposição é FATO; a saída é sempre LEAD ("vale apurar"),
 * NUNCA acusação. Lei é um projeto (pode nem virar lei) — linguagem cuidadosa.
 */
class CamaraScorer
{
    private string $modelo;

    public function __construct(?string $modelo = null)
    {
        $this->modelo = $modelo ?: (string) config('camara.scoring.modelo', 'claude-sonnet-4-6');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * Pontua um LOTE de proposições.
     * $itens = [['materia_id'=>, 'municipio'=>, 'tipo_descricao'=>, 'numero'=>,
     *           'ano'=>, 'autores'=>, 'ementa'=>, 'texto'=>], …]
     *
     * @return array<int,array> por materia_id
     *
     * @throws \RuntimeException
     */
    public function pontuarLote(array $itens): array
    {
        $prompt = $this->montarPrompt($itens);

        $ultimoErro = null;
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            try {
                return $this->parse($this->chamarClaudeCli($prompt), $itens);
            } catch (\Throwable $e) {
                $ultimoErro = $e;
            }
        }

        throw new \RuntimeException('CamaraScorer falhou após retry: ' . $ultimoErro->getMessage(), 0, $ultimoErro);
    }

    private function montarPrompt(array $itens): string
    {
        $lista = '';
        foreach ($itens as $it) {
            $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($it['texto'] ?? ''))), 0, 900);
            $lista .= sprintf(
                "ID %d\nCÂMARA: %s\nTIPO: %s nº %s/%s%s\nAUTOR(ES): %s\nEMENTA: %s\nTEXTO: %s\n\n",
                $it['materia_id'],
                $it['municipio'] ?: '(?)',
                $it['tipo_descricao'] ?: '(?)',
                $it['numero'] ?: '?',
                $it['ano'] ?: '?',
                ! empty($it['complementar']) ? ' (complementar)' : '',
                trim((string) ($it['autores'] ?? '')) ?: '(não informado)',
                trim((string) ($it['ementa'] ?? '')) ?: '(?)',
                $texto ?: '(sem texto)'
            );
        }

        // área de cobertura editorial (config/interesse.php) — pesa no eixo 6
        $cidades = CidadesInteresse::listaPrompt();

        return <<<PROMPT
Você é um EDITOR de jornalismo político local em Santa Catarina, lendo as PROPOSIÇÕES da Câmara de Vereadores (projetos de lei, decretos legislativos, resoluções, emendas à Lei Orgânica) à caça de PAUTA. Para cada proposição abaixo responda UMA pergunta central:

  "Um editor de jornal local olharia este projeto e veria MATÉRIA? Por quê?"

Isso é NOTICIABILIDADE / interesse público.

⚖️ ÉTICA (INEGOCIÁVEL): a proposição é FATO público (e muitas vezes só um PROJETO — pode nem virar lei). "Auto-benefício", "supersalário", "casuísmo" são TESE que só a apuração humana confirma. Você sinaliza um LEAD pra investigar/explicar — JAMAIS afirma irregularidade. Linguagem: "vale apurar", "chama atenção", "merece explicação". NUNCA acuse.

🔭 DUAS LENTES (classifique cada proposição no campo "tipo"):
- "fiscalizacao" 🔴 = RED FLAG a apurar: a câmara/vereador legislando em CAUSA PRÓPRIA (reajuste de subsídio de vereador/prefeito/secretário, verba indenizatória, auxílio, estrutura da própria câmara, criação de cargo comissionado), lei casuística/com endereço certo, projeto bizarro/insólito, benefício a grupo específico, gasto que chama atenção numa cidade pequena.
- "servico" 🟢 = NOVIDADE de impacto direto na cidade (1ª-mão): lei que cria/muda um serviço público (saúde, educação, transporte, zoneamento, IPTU, meio ambiente, animais, mulher, criança), programa social, obra estruturante, isenção/benefício ao cidadão. Informação útil, não denúncia.
- "neutro" = rotina sem ângulo (denominação de via/logradouro, data comemorativa, título de cidadão honorário, utilidade pública de entidade, revogação técnica).

AMBOS 🔴 e 🟢 recebem score_pauta de NOTICIABILIDADE (um bom 🟢 vale 70). Só "neutro" fica baixo. Atenção: denominação de rua, data comemorativa e título honorário são o GROSSO das câmaras e quase sempre "neutro" (score < 30) — não infle.

EIXOS (sinais, qualquer um basta):
1. AUTO-BENEFÍCIO / interesse do próprio Legislativo (subsídio, verba, cargo, estrutura da câmara).
2. CASUÍSMO: lei com endereço/beneficiário específico, feita sob medida.
3. IMPACTO no cidadão: muda imposto, serviço, regra que mexe com a vida da cidade.
4. POLÊMICO/IDEOLÓGICO: tema que divide (costumes, religião, gênero, símbolos).
5. INSÓLITO: objeto que surpreende, é exótico ou irônico pra cidade.
6. RELEVÂNCIA LOCAL/HUMANA: pauta que o cidadão comentaria no grupo de WhatsApp.

📍 ÁREA DE COBERTURA DO JORNAL (pesa no eixo 6 — interesse local): {$cidades}.
Proposição NESSAS cidades tem interesse local maior (o leitor do jornal mora lá) — um
projeto mediano em Penha interessa MAIS que um projeto grande em cidade fora da área.
Cidade fora da área precisa de gancho MAIS forte pra passar de 70. NÃO invente proximidade.

Para CADA proposição responda:
- score_pauta: 0-100 = NOTICIABILIDADE. 80-100 = capa; 60-79 = boa pauta; 40-59 = fraca; <40 = rotina.
- objeto_limpo: UMA linha curta e específica do que o projeto FAZ, em português claro (ex.: "Reajusta o subsídio dos vereadores em 30%", "Cria programa de castração de animais", "Proíbe nome de político vivo em prédio público"). Sem juridiquês. NÃO é acusação.
- gancho_curto: hook de NO MÁXIMO 8 palavras (ex.: "Vereadores votam o próprio aumento?", "Lei sob medida pra quem?"). LEAD/pergunta, NUNCA afirma irregularidade.
- gancho: 1 frase — por que vira pauta (tom de LEAD).
- tipo: "fiscalizacao" | "servico" | "neutro".
- tipo_de_gancho: TEXTO LIVRE curto (ex.: "reajuste de subsídio", "lei casuística", "novo serviço de saúde", "data comemorativa", "denominação de rua").
- o_que_apurar: array de 2-4 bullets — o que checar, quem ouvir, qual o status da tramitação.
- angulo_sugerido: 1 frase — o ângulo/título possível.
- flags: array com os números dos EIXOS que bateram (ex.: [1,2]).

RESPONDA APENAS com um array JSON válido, um objeto por proposição, sem markdown e sem texto fora do JSON:
[{"id": <id>, "score_pauta": <0-100>, "tipo": "fiscalizacao|servico|neutro", "objeto_limpo": "...", "gancho_curto": "...", "gancho": "...", "tipo_de_gancho": "...", "o_que_apurar": ["...","..."], "angulo_sugerido": "...", "flags": [1,2]}]

PROPOSIÇÕES:

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

    /** @return array<int,array> por materia_id */
    private function parse(string $texto, array $itens): array
    {
        $texto = trim($texto);
        if (preg_match('/\[.*\]/s', $texto, $m)) {
            $texto = $m[0];
        }
        $arr = json_decode($texto, true);
        if (! is_array($arr)) {
            throw new \RuntimeException('JSON inválido do faro câmara: ' . mb_substr($texto, 0, 200));
        }

        $idsEsperados = array_map(fn ($i) => (int) $i['materia_id'], $itens);
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
            throw new \RuntimeException('Faro câmara não devolveu os ids: ' . implode(',', $faltando));
        }

        return $out;
    }
}
