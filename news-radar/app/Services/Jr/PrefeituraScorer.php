<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\Process;

/**
 * BLOCO 3 (Goal 02/07) — faro pra NOTÍCIA INSTITUCIONAL de prefeitura (release
 * oficial). Lente principal 🟢 serviço/1ª-mão: as prefeituras de interesse são
 * anunciantes e a notícia oficial delas é pauta de SERVIÇO valiosa (obra, edital,
 * concurso, evento, saúde). Plumbing idêntico ao DomScorer (claude-cli, lote,
 * retry 1x), prompt próprio. ISOLADO do juiz de notícias.
 *
 * ⚖️ Ética travada: release é a VERSÃO OFICIAL de uma parte — a saída sempre
 * lembra isso e o rascunho derivado sinaliza a origem. NUNCA acusação.
 */
class PrefeituraScorer
{
    private string $modelo;

    public function __construct(?string $modelo = null)
    {
        $this->modelo = $modelo ?: (string) config('prefeitura.scoring.modelo', 'claude-sonnet-4-6');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * Pontua um LOTE. $itens = [['ato_id'=>int,'municipio'=>,'titulo'=>,
     * 'texto'=>,'data_pub'=>], …].
     *
     * @return array<int,array> por ato_id (mesmo contrato do DomScorer)
     *
     * @throws \RuntimeException se falhar após retry
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

        throw new \RuntimeException('PrefeituraScorer falhou após retry: ' . $ultimoErro->getMessage(), 0, $ultimoErro);
    }

    private function montarPrompt(array $itens): string
    {
        $lista = '';
        foreach ($itens as $it) {
            $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($it['texto'] ?? ''))), 0, 900);
            $lista .= sprintf(
                "ID %d\nMUNICÍPIO: %s | DATA: %s\nTÍTULO: %s\nTEXTO/RESUMO: %s\n\n",
                $it['ato_id'],
                $it['municipio'] ?: '(?)',
                $it['data_pub'] ?: '(?)',
                trim((string) ($it['titulo'] ?? '')),
                $texto ?: '(sem texto — só o título)'
            );
        }

        $cidades = CidadesInteresse::listaPrompt();

        return <<<PROMPT
Você é um EDITOR de jornal local em Santa Catarina lendo os RELEASES OFICIAIS das prefeituras da sua área. Para cada notícia institucional abaixo, responda: "isso rende matéria de SERVIÇO/1ª-mão pro leitor da cidade?"

⚠️ NATUREZA DA FONTE: release de prefeitura é a VERSÃO OFICIAL de uma parte interessada. É insumo legítimo de pauta de serviço (obra, edital, concurso, vacina, evento, mutirão), mas NÃO é verdade apurada. NUNCA trate promessa como fato consumado.

🔭 LENTES (campo "tipo"):
- "servico" 🟢 (a lente PRINCIPAL aqui): informação ÚTIL e CONCRETA pro cidadão — edital/concurso aberto (com prazo), obra que muda rotina (rua interditada, ponte, escola), campanha de saúde com data/local, mutirão, novo serviço público, evento cultural relevante. Quanto mais AÇÃO CONCRETA + PRAZO + LOCAL, maior o score.
- "fiscalizacao" 🔴 (rara aqui, mas fique atento): o release, sem querer, revela algo a apurar — número que não fecha, contrato citado de passagem, promessa antiga renovada, inauguração de obra atrasada. É LEAD, não acusação.
- "neutro": autopromoção pura (prefeito visita, assina, participa, recebe), agenda protocolar, felicitação de data comemorativa, institucional vazio.

📍 ÁREA DE COBERTURA (interesse local pesa MUITO): {$cidades}. Cidade do núcleo com serviço concreto = pauta certa. Fora da área precisa ser MUITO forte.

REGRA DE OURO: "o leitor da cidade PRECISA saber disso pra viver a semana?" (prazo de inscrição, rua fechada, vacina disponível) → score alto. "A prefeitura quer aparecer?" → neutro, score baixo.

Para CADA notícia responda:
- score_pauta: 0-100 = utilidade/noticiabilidade. 80-100 = serviço quente (prazo/impacto direto), publicar HOJE; 60-79 = boa pauta de serviço; 40-59 = fraca; <40 = autopromoção/rotina.
- objeto_limpo: UMA linha concreta do QUE acontece (ex.: "Concurso com 40 vagas na educação — inscrições até 15/07").
- gancho_curto: hook de NO MÁXIMO 8 palavras.
- gancho: 1 frase — por que o leitor se importa.
- tipo: "servico" | "fiscalizacao" | "neutro".
- tipo_de_gancho: curto (ex.: "concurso aberto", "obra muda trânsito", "campanha de vacina").
- o_que_apurar: 2-4 bullets — o que confirmar de forma independente (sempre inclua checar com fonte não-oficial quando couber).
- angulo_sugerido: 1 frase — o ângulo pro jornal (foco no leitor, não na prefeitura).
- flags: array vazio ou [1] se o release revela algo a fiscalizar.

RESPONDA APENAS com um array JSON válido, um objeto por notícia, sem markdown:
[{"id": <id>, "score_pauta": <0-100>, "tipo": "servico|fiscalizacao|neutro", "objeto_limpo": "...", "gancho_curto": "...", "gancho": "...", "tipo_de_gancho": "...", "o_que_apurar": ["..."], "angulo_sugerido": "...", "flags": []}]

NOTÍCIAS:

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

    /** @return array<int,array> por ato_id (mesmo parse defensivo do DomScorer) */
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
