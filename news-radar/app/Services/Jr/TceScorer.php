<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Process;

/**
 * Faro TCE — pontua processos/decisões do Tribunal de Contas pela ótica de um
 * EDITOR (noticiabilidade), reaproveitando o motor (Sonnet, dual-lens 🔴/🟢,
 * claude-cli) com PROMPT de lente TCE. ISOLADO do juiz/Opus.
 *
 * ⚖️ Ética: a decisão é FATO público. "Irregular/multa/conta rejeitada" só vale
 * se ESTIVER NO DESFECHO do próprio TCE (aí é fato citável: "o TCE multou/julgou
 * irregular"). Representação/denúncia ainda EM APURAÇÃO = LEAD, nunca condenação.
 */
class TceScorer
{
    private string $modelo;

    public function __construct(?string $modelo = null)
    {
        $this->modelo = $modelo ?: (string) config('tce.scoring.modelo', 'claude-sonnet-4-6');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * $itens = [['id'=>, 'tipo_proc'=>, 'assunto'=>, 'responsavel'=>,
     *           'unidade_gestora'=>, 'desfecho'=>, 'texto'=>], …]
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
                return $this->parse($this->chamarClaudeCli($prompt), $itens);
            } catch (\Throwable $e) {
                $ultimoErro = $e;
            }
        }

        throw new \RuntimeException('TceScorer falhou após retry: ' . $ultimoErro->getMessage(), 0, $ultimoErro);
    }

    private function montarPrompt(array $itens): string
    {
        $lista = '';
        foreach ($itens as $it) {
            $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($it['texto'] ?? ''))), 0, 800);
            $lista .= sprintf(
                "ID %d\nTIPO: %s\nUNIDADE GESTORA: %s\nRESPONSÁVEL: %s\nASSUNTO: %s\nDESFECHO (decisão do TCE): %s\nTEXTO: %s\n\n",
                $it['id'],
                $it['tipo_proc'] ?: '(?)',
                $it['unidade_gestora'] ?: '(?)',
                trim((string) ($it['responsavel'] ?? '')) ?: '(?)',
                trim((string) ($it['assunto'] ?? '')) ?: '(?)',
                trim((string) ($it['desfecho'] ?? '')) ?: '(não detectado / em tramitação)',
                $texto ?: '(sem texto)'
            );
        }

        // área de cobertura editorial (config/interesse.php) — interesse local
        $cidades = CidadesInteresse::listaPrompt();

        return <<<PROMPT
Você é um EDITOR de jornalismo investigativo local em Santa Catarina, lendo o Diário do TRIBUNAL DE CONTAS (TCE-SC). Cada item é um processo/decisão: representação, inspeção, auditoria, denúncia, prestação de contas ou decisão singular sobre a gestão de dinheiro público. Para cada um responda:

  "Um editor de jornal local olharia isto e veria MATÉRIA? Por quê?"

⚖️ ÉTICA (INEGOCIÁVEL): distinga DOIS casos:
- Se o DESFECHO mostra o TCE DECIDINDO (multou, julgou contas IRREGULARES, aplicou débito, determinou devolução): isso é FATO citável — "o TCE multou/julgou irregular X". Pode afirmar o que o TRIBUNAL decidiu (não inventar além do desfecho).
- Se é só representação/denúncia/inspeção EM APURAÇÃO (ou desfecho de arquivamento/"não conhecer"): é LEAD — "o TCE analisa/apura X" — NUNCA afirme irregularidade. Arquivar/"não conhecer" = pouca pauta.
JAMAIS chame o gestor de culpado além do que o TCE efetivamente decidiu.

O que ELEVA a noticiabilidade:
- DESFECHO duro: MULTA a gestor, CONTAS REJEITADAS/irregulares, DÉBITO/devolução, superfaturamento reconhecido, representação procedente.
- PODER PÚBLICO local no foco: Prefeitura, Câmara, Município, fundo, autarquia municipal (mais que órgão estadual distante).
- Tema sensível: licitação/dispensa, obra, contrato, folha, saúde, valor alto.

📍 ÁREA DE COBERTURA DO JORNAL (interesse local): {$cidades}.
Processo NESSAS cidades tem interesse local maior (o leitor do jornal mora lá) — uma
decisão mediana sobre Penha interessa MAIS que uma grande sobre cidade fora da área.
Cidade fora da área precisa de gancho MAIS forte pra passar de 70. NÃO invente
proximidade. A âncora geográfica é a UNIDADE GESTORA.

O que REBAIXA (score baixo, "neutro"): aposentadoria/pensão de rotina, "não conhecer"/arquivamento sem mérito, ato puramente cadastral, reexame técnico sem desfecho relevante.

🔭 DUAS LENTES (campo "tipo"):
- "fiscalizacao" 🔴 = TCE apontando/decidindo problema na gestão pública (multa, irregular, representação com substância). LEAD ou FATO conforme o desfecho.
- "servico" 🟢 = decisão que vira informação útil (TCE manda corrigir um serviço, garante um direito) sem ser exatamente denúncia de gestor.
- "neutro" = rotina/arquivamento/cadastral.
AMBOS 🔴 e 🟢 recebem score; só "neutro" fica baixo (<40).

Para CADA item responda:
- score_pauta: 0-100. 80-100 = TCE multou/rejeitou contas de prefeitura (capa); 60-79 = boa pauta; 40-59 = fraca; <40 = rotina/arquivamento.
- objeto_limpo: UMA linha jornalística do que houve (ex.: "TCE julga irregular dispensa de licitação em Araquari", "TCE analisa representação sobre pregão da Prefeitura de X"). Respeite a distinção fato/lead acima.
- gancho_curto: hook de ≤8 palavras (ex.: "TCE multa gestor de X", "Contas rejeitadas em Y").
- gancho: 1 frase — por que vira pauta.
- tipo: "fiscalizacao" | "servico" | "neutro".
- tipo_de_gancho: TEXTO LIVRE curto (ex.: "multa a gestor", "contas irregulares", "representação licitação", "auditoria de obra").
- o_que_apurar: array 2-4 bullets — o que checar, ouvir o gestor, status do processo/recurso.
- angulo_sugerido: 1 frase — ângulo/título possível.
- flags: array vazio [].

RESPONDA APENAS com um array JSON válido, um objeto por item, sem markdown:
[{"id": <id>, "score_pauta": <0-100>, "tipo": "fiscalizacao|servico|neutro", "objeto_limpo": "...", "gancho_curto": "...", "gancho": "...", "tipo_de_gancho": "...", "o_que_apurar": ["..."], "angulo_sugerido": "...", "flags": []}]

PROCESSOS:

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

    /** @return array<int,array> por id */
    private function parse(string $texto, array $itens): array
    {
        $texto = trim($texto);
        if (preg_match('/\[.*\]/s', $texto, $m)) {
            $texto = $m[0];
        }
        $arr = json_decode($texto, true);
        if (! is_array($arr)) {
            throw new \RuntimeException('JSON inválido do faro TCE: ' . mb_substr($texto, 0, 200));
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
            throw new \RuntimeException('Faro TCE não devolveu os ids: ' . implode(',', $faltando));
        }

        return $out;
    }
}
