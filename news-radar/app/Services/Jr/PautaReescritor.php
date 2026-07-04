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
        // BLOCO 7 (simplificar 03/07): driver/modelo decididos no JuizLlm
        // (jrlink.drivers + jrlink.modelos_openai); este é só o fallback claude.
        $modelo = (string) config('radar_civico.rascunho.modelo', 'claude-opus-4-8');
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
            'modelo'     => $this->juiz->modelo(), // o que REALMENTE rodou (antes mentia no driver openai)
        ];
    }

    /**
     * REESCRITA UNIFICADA (Radar JR, Goal 3): junta o texto de TODOS os portais
     * que cobriram um assunto e gera UMA pauta no padrão JR — ~12 títulos, linha
     * fina, matéria, 8 tags, lacunas. Concorrente é fonte pra CONFIRMAR o fato,
     * nunca texto pra copiar (anti-plágio). Reusa completarJson() (claude-cli
     * Opus), SEM tocar o prompt do juiz.
     *
     * @param  array<int,array{host:string,titulo:string,texto:string}>  $portais
     * @return array{titulos:array,titulo_principal:string,linha_fina:string,materia:string,tags:array,cidade:?string,editoria:string,lacunas:array,modelo:string}
     *
     * @throws \RuntimeException se a reescrita falhar/voltar inválida
     */
    public function reescreverUnificado(array $portais, ?string $cidade): array
    {
        // BLOCO 7 (simplificar 03/07): idem reescrever() — fallback claude via config.
        $modelo = (string) config('radar_civico.rascunho.modelo', 'claude-opus-4-8');
        $prompt = $this->promptUnificado($portais, $cidade);
        $arr = $this->juiz->completarJson($prompt, 'reescrita_unificada', count($portais), $modelo);

        $r = $arr[0] ?? null;
        if (! is_array($r) || empty($r['materia']) || empty($r['titulos'])) {
            throw new \RuntimeException('Reescrita unificada voltou vazia/inválida.');
        }

        $limpaLista = function ($v): array {
            return is_array($v) ? array_values(array_filter(array_map(fn ($x) => trim((string) $x), $v))) : [];
        };
        $titulos = $limpaLista($r['titulos'] ?? []);
        $tags = $limpaLista($r['tags'] ?? []);
        $lac = $limpaLista($r['lacunas'] ?? []);

        return [
            'titulos'          => array_slice($titulos, 0, 12),
            'titulo_principal' => $titulos[0] ?? '',
            'linha_fina'       => trim((string) ($r['linha_fina'] ?? '')),
            'materia'          => trim((string) $r['materia']),
            'tags'             => array_slice($tags, 0, 8),
            'cidade'           => isset($r['cidade']) && $r['cidade'] !== '' ? trim((string) $r['cidade']) : $cidade,
            'editoria'         => strtolower(trim((string) ($r['editoria'] ?? 'geral'))),
            'lacunas'          => $lac,
            'modelo'           => $this->juiz->modelo(), // o que REALMENTE rodou
        ];
    }

    /**
     * @param  array<int,array{host:string,titulo:string,texto:string}>  $portais
     */
    private function promptUnificado(array $portais, ?string $cidade): string
    {
        $cidadeTxt = $cidade ? $cidade : '(deduza do texto; se não houver, null)';
        $blocos = '';
        foreach (array_values($portais) as $i => $p) {
            $n = $i + 1;
            $txt = trim((string) ($p['texto'] ?? ''));
            $txt = mb_substr($txt, 0, 4000); // teto por portal pra caber no contexto
            $blocos .= "### PORTAL {$n} — {$p['host']}\nTÍTULO: {$p['titulo']}\nTEXTO:\n{$txt}\n\n";
        }

        return <<<PROMPT
Você é editor do Jornal Razão, jornal regional de Tijucas/SC (litoral e Vale do Itajaí). Abaixo estão as COBERTURAS de VÁRIOS portais concorrentes sobre o MESMO fato. Sua tarefa: APURAR o fato cruzando as fontes e ESCREVER UMA matéria nova no padrão JR — do zero, com suas palavras.

REGRAS DE OURO (inquebráveis):
- Concorrente é fonte pra CONFIRMAR o fato, NUNCA texto pra copiar. NÃO parafraseie frase a frase nem reaproveite trechos. Reescreva de verdade. (anti-plágio)
- Fato é fato, versão é versão: o que for afirmação de alguém vai ATRIBUÍDO ("segundo a polícia", "conforme a prefeitura", "de acordo com testemunhas", "a polícia apura") à INSTITUIÇÃO, nunca ao documento/boletim em si. Confirmado = indicativo; versão de alguém = futuro do pretérito ("teria fugido"). GUARDA SIMÉTRICA: toda afirmação não confirmada por conta própria precisa de atribuição no mesmo parágrafo OU futuro do pretérito — nunca desatribuída, mesmo que pareça mais fluido.
- NUNCA invente nome, data, número, fala, causa ou conclusão que não esteja em PELO MENOS uma das fontes. Se um dado essencial não aparece ou as fontes divergem, NÃO chute — registre em "lacunas".
- Cidade de SC correta: use exatamente a que as fontes indicam (sugestão: "{$cidadeTxt}"). Nunca invente município.

FORMATO — mate estes vícios (erros reais já vistos neste tipo de reescrita):
- COMPLETUDE MÁXIMA SEM REPETIÇÃO: use TODOS os fatos e ângulos que as fontes trazem — a matéria mais completa possível é a meta. Não repita o mesmo fato entre linha_fina e o 1º parágrafo; cada parágrafo soma um fato/ângulo novo cruzado entre as fontes. Só encurte quando os fatos acabarem — nunca estique com reformulação.
- Nunca emende 2 parágrafos abrindo os dois com fórmula de atribuição; varie fato→fonte / fonte→fato sem nunca remover a atribuição.
- Parágrafos de tamanho variado, nunca clichê que só reafirma o título. Voz ativa com sujeito concreto.
- Cada frase passa no teste "o leitor sabe algo novo depois dela?" — corte abstração vaga e frase-enchimento.
- Nunca fale SOBRE a cobertura dos portais ("os veículos noticiaram que…") — narre o FATO apurado.
- Dado concreto presente em qualquer uma das fontes (endereço, telefone, nome, horário, número) vai na matéria; "lacunas" é só pro que não aparece em NENHUMA fonte ou pro que as fontes divergem.
- NUNCA escreva "não foi informado", "não detalha", "não divulgou" nem variação disso DENTRO da matéria — o que falta vive só no campo "lacunas".
- Data: 1ª menção = dia da semana + dia entre parênteses (ex. "sexta-feira (3)"), só se pelo menos uma fonte ancorar a data com segurança — nunca converta dêixis relativa sem data explícita; se nenhuma fonte ancorar, mantenha o formato das fontes e jogue "confirmar a data" em lacunas. Título sem dêixis relativa.
- Sigla na 1ª menção ganha glosa curta.

PROIBIDO sempre: "além disso", "vale ressaltar/destacar", "nesse sentido", fecho-resumo genérico, travessão (—) estilístico, ponto final no título, tradução de inglês, qualquer invenção pra "dar vida" ao texto.

- materia: PROSA CORRIDA em parágrafos de tamanho variado conforme o fato (4 a 7), lead jornalístico no 1º parágrafo (o quê/quem/quando/onde). Os blocos (lead, contexto, atribuição, desdobramento, status) são CHECKLIST INTERNO — não escreva rótulos no texto. Sem markdown, sem subtítulos. Separe parágrafos com \\n\\n.
- titulos: 12 opções de título na VOZ JR — sentence case (só 1ª maiúscula + nomes próprios), SEMPRE com a cidade (de preferência meio/fim), informativo, sem ALL CAPS, sem ponto final, sem clickbait raso.
- linha_fina: 1 frase (máx ~200 caracteres) que complementa o título principal sem repeti-lo.
- tags: exatamente 8 termos relevantes (cidade, tema, entidades citadas), minúsculas.
- editoria: UMA destas: seguranca, politica, economia, saude, educacao, transito, infraestrutura, meioambiente, cultura, esporte, entretenimento, turismo, tecnologia, geral.

RESPONDA APENAS com um array JSON de UM objeto, sem markdown e sem texto fora do JSON:
[{"titulos":["t1",...,"t12"],"linha_fina":"...","materia":"...","tags":["...x8"],"cidade":"..."|null,"editoria":"...","lacunas":["..."]}]

COBERTURAS DOS PORTAIS:
{$blocos}
PROMPT;
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

FATO VS VERSÃO (inegociável): apure só o que está no release. Nunca invente
fato, número, nome, data ou fala ausente — se faltar algo essencial, vai em
"lacunas", nunca na matéria. Atribua a informação à fonte quando for
afirmação dela ("segundo a prefeitura", "conforme o comunicado") — mas cite a
INSTITUIÇÃO, nunca o documento em si ("segundo o ofício/comunicado nº…").
Confirmado = indicativo; versão de alguém = futuro do pretérito. GUARDA
SIMÉTRICA: toda afirmação não confirmada por conta própria precisa de
atribuição no mesmo parágrafo OU futuro do pretérito — nunca solta.

FORMATO — mate estes vícios (são erros reais já vistos em textos deste tipo):
- COMPLETUDE MÁXIMA SEM REPETIÇÃO: use TODOS os fatos do release — a matéria
  mais completa possível é a meta. Não repita o mesmo fato entre linha_fina e
  o 1º parágrafo; cada parágrafo soma informação nova do release. Só encurte
  quando os fatos do release acabarem — nunca estique com reformulação.
- Nunca emende 2 parágrafos abrindo os dois com fórmula de atribuição
  ("Segundo…", "De acordo com…"); varie fato→fonte / fonte→fato sem nunca
  remover a atribuição.
- Parágrafos de tamanho variado, nunca um clichê que só reafirma o título.
  Voz ativa com sujeito concreto (evite passiva sem agente).
- Cada frase tem que passar no teste "o leitor sabe algo novo depois dela?" —
  corte abstração vaga ("em meio a…", "diante de…") e frase-enchimento.
- Nunca fale SOBRE o release ou o canal de divulgação — narre o FATO
  diretamente, não o ato de divulgar.
- Dado concreto que JÁ ESTÁ no release (endereço, telefone, nome, horário,
  número) vai na matéria. "Lacunas" é só pro que NÃO está no release.
- NUNCA escreva "não foi informado", "não detalha", "não divulgou" nem
  variação disso DENTRO da matéria — o que falta vive só no campo "lacunas".
- Data: 1ª menção = dia da semana + dia entre parênteses (ex. "sexta-feira
  (3)"), consistente no texto todo, só se o release ancorar a data com
  segurança — NUNCA converta/chute dia da semana a partir de dêixis relativa
  ("nesta sexta") sem uma data explícita no release; se não der pra ancorar,
  mantenha o formato exato do release e jogue "confirmar a data" em lacunas.
  Título sem dêixis relativa.
- Se o release nomeia bairro/rua/ponto, a matéria nomeia; se não nomeia, não
  invente — vai em lacunas.
- Sigla na 1ª menção ganha glosa curta.

PROIBIDO sempre: "além disso", "vale ressaltar/destacar", "nesse sentido",
fecho-resumo genérico, travessão (—) estilístico, ponto final no título,
tradução de inglês, qualquer invenção pra "dar vida" ao texto.

- Título em SENTENCE CASE em português (só a 1ª letra maiúscula + nomes próprios), SEMPRE com a cidade (de preferência meio/fim), informativo, sem ALL CAPS, sem ponto final, sem clickbait.
- linha_fina: 1 frase (máx ~200 caracteres) que complementa o título sem repeti-lo.
- materia: prosa corrida em parágrafos de tamanho variado conforme o fato (3 a 6 parágrafos), lead jornalístico no 1º parágrafo (o quê/quem/quando/onde), tom factual e sóbrio. Sem markdown, sem títulos internos. Separe parágrafos com \\n\\n.
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
