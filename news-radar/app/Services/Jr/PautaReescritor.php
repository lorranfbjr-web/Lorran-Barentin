<?php

namespace App\Services\Jr;

/**
 * Stage 2 do elo de publicação: classifica uma captura (fria/quente, reusando
 * a régua v3 do JuizLlm — SEM bumpar prompt_versao) e, se FRIA e liberada pelo
 * gate, REESCREVE no padrão Jornal Razão (reescrita real, nunca paráfrase) via
 * o driver claude-cli do JuizLlm.
 *
 * NÃO toca o prompt do juiz nem a régua. A classificação usa julgarLote() como
 * está; a reescrita usa completarJson() (entrada genérica já existente), com
 * prompt PRÓPRIO e modelo forte (Opus) pra qualidade da prosa.
 */
class PautaReescritor
{
    /** Score do juiz abaixo disso = pauta FRIA (não é post quente do perfil). */
    public const LIMIAR_FRIA = 60;

    public function __construct(private JuizLlm $juiz) {}

    /**
     * Classifica a captura pela régua v3. Deriva título+lead do texto cru.
     *
     * @return array{escopo:string,eh_pauta:bool,tipo_gancho:string,cidade:?string,score_llm:int,motivo:string}
     */
    public function classificar(string $texto, ?string $cidadeHint = null): array
    {
        [$titulo, $lead] = $this->tituloLead($texto);
        $vereditos = $this->juiz->julgarLote([[ 'id' => 1, 'titulo' => $titulo, 'lead' => $lead ]]);

        return $vereditos[1];
    }

    public function ehFria(array $veredito): bool
    {
        // <60 = "fraco/talvez/não parece post" na escala do juiz = pauta fria,
        // o material institucional/serviço/agenda que este elo publica.
        return (int) ($veredito['score_llm'] ?? 0) < self::LIMIAR_FRIA;
    }

    public function ganchoFilaHumana(array $veredito): bool
    {
        return in_array($veredito['tipo_gancho'] ?? '', ['solidariedade', 'vaquinha'], true);
    }

    /**
     * Reescreve no padrão JR. Retorna a matéria pronta pra virar rascunho.
     *
     * @return array{titulo:string,linha_fina:string,materia:string,tags:array,cidade:?string,lacunas:array,modelo:string}
     *
     * @throws \RuntimeException se a reescrita falhar/voltar inválida
     */
    public function reescrever(string $texto, ?string $cidade, string $fonte): array
    {
        $modelo = 'claude-opus-4-8';
        $prompt = $this->promptReescrita($texto, $cidade, $fonte);
        $arr = $this->juiz->completarJson($prompt, 'reescrita_pauta', 1, $modelo);

        $r = $arr[0] ?? null;
        if (! is_array($r) || empty($r['titulo']) || empty($r['materia'])) {
            throw new \RuntimeException('Reescrita voltou vazia/inválida.');
        }

        $tags = $r['tags'] ?? [];
        $tags = is_array($tags) ? array_values(array_filter(array_map('trim', $tags))) : [];
        $lac = $r['lacunas'] ?? [];
        $lac = is_array($lac) ? array_values(array_filter(array_map('trim', $lac))) : [];

        return [
            'titulo'     => trim((string) $r['titulo']),
            'linha_fina' => trim((string) ($r['linha_fina'] ?? '')),
            'materia'    => trim((string) $r['materia']),
            'tags'       => array_slice($tags, 0, 6),
            'cidade'     => isset($r['cidade']) && $r['cidade'] !== '' ? trim((string) $r['cidade']) : $cidade,
            'editoria'   => strtolower(trim((string) ($r['editoria'] ?? 'geral'))),
            'lacunas'    => $lac,
            'modelo'     => $modelo,
        ];
    }

    /** @return array{0:string,1:string} [titulo_derivado, lead_derivado] */
    private function tituloLead(string $texto): array
    {
        $t = trim(preg_replace('/\s+/u', ' ', $texto));
        // 1ª frase (até pontuação forte) como pseudo-título; resto como lead.
        if (preg_match('/^(.{20,140}?[.!?])\s/u', $t, $m)) {
            $titulo = rtrim($m[1], '.!? ');
            $lead = trim(mb_substr($t, mb_strlen($m[1])));
        } else {
            $titulo = mb_substr($t, 0, 120);
            $lead = mb_substr($t, 120, 280);
        }

        return [$titulo, mb_substr($lead, 0, 280)];
    }

    private function promptReescrita(string $texto, ?string $cidade, string $fonte): string
    {
        $cidadeTxt = $cidade ? $cidade : '(não informada no release)';
        $texto = trim($texto);

        return <<<PROMPT
Você é redator do Jornal Razão, jornal regional de Tijucas/SC (litoral e Vale do Itajaí). Recebeu o RELEASE BRUTO abaixo, vindo de uma fonte oficial ({$fonte}). Sua tarefa é REESCREVER como notícia no padrão do jornal — reescrita de VERDADE, do zero, com suas próprias palavras e estrutura jornalística. NUNCA parafraseie frase a frase nem copie trechos do release.

REGRAS:
- Apure SÓ o que está no release. NÃO invente fatos, números, nomes, datas ou falas que não estejam no texto. Se algo essencial faltar, liste em "lacunas".
- Atribua a informação à fonte oficial quando for afirmação dela ("segundo a prefeitura", "de acordo com o órgão", "conforme o comunicado").
- Título em SENTENCE CASE em português (só a 1ª letra maiúscula + nomes próprios), informativo, sem ALL CAPS, sem ponto final, sem clickbait.
- linha_fina: 1 frase (máx ~200 caracteres) que complementa o título sem repeti-lo.
- materia: prosa corrida em parágrafos curtos (3 a 6 parágrafos), lead jornalístico no 1º parágrafo (o quê/quem/quando/onde), tom factual e sóbrio. Sem markdown, sem títulos internos. Separe parágrafos com \\n\\n.
- tags: 3 a 6 termos relevantes (cidade, tema, entidades citadas), minúsculas.
- cidade: a cidade principal da notícia (use "{$cidadeTxt}" se o release indicar; senão null).
- editoria: classifique em UMA destas (a que melhor descreve o fato): seguranca, politica, economia, saude, educacao, transito, infraestrutura, meioambiente, cultura, esporte, entretenimento, turismo, tecnologia, geral.

RESPONDA APENAS com um array JSON de UM objeto, sem markdown e sem texto fora do JSON:
[{"titulo":"...","linha_fina":"...","materia":"...","tags":["...","..."],"cidade":"..."|null,"editoria":"...","lacunas":["..."]}]

RELEASE BRUTO:
\"\"\"
{$texto}
\"\"\"
PROMPT;
    }
}
