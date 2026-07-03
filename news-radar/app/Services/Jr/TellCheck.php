<?php

namespace App\Services\Jr;

/**
 * GOAL revisao-raspagem (03/07) — O4/O5. Guarda anti-IA no caminho do envio
 * (auto-rascunho E Mesa): heurística grátis (PHP, regex/similaridade) SEMPRE
 * + cético gpt-4o-mini SEMPRE (cap diário pequeno = custo ~zero). NUNCA é
 * gate — nunca segura nem descarta rascunho, só regenera 1x e anota flag.
 *
 * Fórmula fixa do cético: nota = 10 − 3×tell_grave − 1×tell_leve (mín. 0).
 */
class TellCheck
{
    /** Muletas clássicas — continuam PROIBIDAS (§6.2 do goal). */
    private const MULETAS = [
        'além disso', 'vale ressaltar', 'vale destacar', 'é importante ressaltar',
        'cabe destacar', 'nesse sentido', 'não apenas', 'reforça o compromisso',
        'segue firme no propósito', 'em meio a', 'diante de',
    ];

    public function __construct(private JuizLlm $juiz) {}

    /**
     * @param  string  $fonte  texto_bruto do ato/release — sem isso o cético não tem como checar grounding de verdade.
     * @return array{nota:int,grave:bool,tells:array<int,array{tell:string,grave:bool,fonte:string}>}
     */
    public function avaliar(string $titulo, string $lead, string $corpo, string $fonte = ''): array
    {
        $heur = $this->heuristica($titulo, $lead, $corpo);
        $cet = $this->cetico($titulo, $lead, $corpo, $fonte);

        $tells = $heur;
        foreach ($cet['tells'] as $t) {
            $tells[] = ['tell' => (string) ($t['tell'] ?? ''), 'grave' => (bool) ($t['grave'] ?? false), 'fonte' => 'cetico'];
        }

        $graveHeurForaDoCetico = ! empty(array_filter($heur, fn ($t) => $t['grave']));
        $nota = (int) $cet['nota'];
        if ($graveHeurForaDoCetico) {
            $nota = min($nota, 4);
        }

        return [
            'nota' => max(0, $nota),
            'grave' => $graveHeurForaDoCetico || (bool) array_filter($tells, fn ($t) => $t['grave']),
            'tells' => $tells,
        ];
    }

    /** @return array<int,array{tell:string,grave:bool,fonte:string}> */
    private function heuristica(string $titulo, string $lead, string $corpo): array
    {
        $tells = [];
        $paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', trim($corpo)) ?: [])));

        // 1) eco título↔lead e lead↔1º parágrafo.
        if ($lead !== '') {
            similar_text(mb_strtolower($titulo), mb_strtolower($lead), $pct1);
            if ($pct1 > 70) {
                $tells[] = ['tell' => 'eco título↔lead (similaridade '.round($pct1).'%)', 'grave' => true, 'fonte' => 'heuristica'];
            }
        }
        if ($lead !== '' && ! empty($paras)) {
            similar_text(mb_strtolower($lead), mb_strtolower($paras[0]), $pct2);
            if ($pct2 > 70) {
                $tells[] = ['tell' => 'eco lead↔1º parágrafo do corpo (similaridade '.round($pct2).'%)', 'grave' => true, 'fonte' => 'heuristica'];
            }
        }

        // 2) atribuição mecânica em série — 2+ parágrafos DO CORPO seguidos
        //    abrindo com fórmula de atribuição (regex sintático, não lista fixa).
        $reAtrib = '/^(Segundo|De acordo|Conforme)\b|^[OA]s?\s+[A-ZÀ-Ü][\wÀ-ü ]+\s+(informou|informa|divulgou|comunicou|cita|confirmou)\b/u';
        $seguidos = 0;
        $maxSeguidos = 0;
        foreach ($paras as $p) {
            if (preg_match($reAtrib, $p)) {
                $seguidos++;
                $maxSeguidos = max($maxSeguidos, $seguidos);
            } else {
                $seguidos = 0;
            }
        }
        if ($maxSeguidos >= 2) {
            $tells[] = ['tell' => 'atribuição mecânica em série ('.$maxSeguidos.' parágrafos seguidos)', 'grave' => true, 'fonte' => 'heuristica'];
        }

        // 3) monotonia de tamanho — só com >=3 parágrafos (com 2 é ruído).
        if (count($paras) >= 3) {
            $lens = array_map('mb_strlen', $paras);
            $avg = array_sum($lens) / count($lens);
            $desvio = $avg > 0 ? (max($lens) - min($lens)) / $avg : 0;
            if ($desvio < 0.15) {
                $tells[] = ['tell' => 'parágrafos de tamanho uniforme (monotonia declarativa)', 'grave' => false, 'fonte' => 'heuristica'];
            }
        }

        // 4) vazamento de bastidor — falar SOBRE o release/canal, não o fato.
        if (preg_match('/por meio de (uma? )?(nota|not[íi]cia|release|comunicado)/ui', $corpo.' '.$lead)) {
            $tells[] = ['tell' => 'vazamento de bastidor (fala sobre o canal de divulgação, não o fato)', 'grave' => true, 'fonte' => 'heuristica'];
        }

        // 5) muletas clássicas.
        $textoTodo = mb_strtolower($titulo.' '.$lead.' '.$corpo);
        foreach (self::MULETAS as $m) {
            if (mb_strpos($textoTodo, $m) !== false) {
                $tells[] = ['tell' => 'muleta clássica: "'.$m.'"', 'grave' => false, 'fonte' => 'heuristica'];
            }
        }

        // 6) travessão estilístico (proibição v6 literal).
        if (mb_strpos($corpo.$lead.$titulo, '—') !== false) {
            $tells[] = ['tell' => 'travessão (—) estilístico', 'grave' => false, 'fonte' => 'heuristica'];
        }

        // 7) ponto final no título.
        if (preg_match('/\.\s*$/u', trim($titulo))) {
            $tells[] = ['tell' => 'ponto final no título', 'grave' => false, 'fonte' => 'heuristica'];
        }

        // 8) dêixis de data sem âncora no título ("nesta sexta" sem "(3)").
        if (preg_match('/\b(nest[ae]|hoje|amanh[ãa])\s+(segunda|ter[çc]a|quarta|quinta|sexta|s[áa]bado|domingo)?/ui', $titulo)
            && ! preg_match('/\(\d{1,2}\)/', $titulo)) {
            $tells[] = ['tell' => 'dêixis de data relativa sem âncora no título', 'grave' => false, 'fonte' => 'heuristica'];
        }

        return $tells;
    }

    /** @return array{nota:int,tells:array<int,array{tell:string,grave:bool}>} */
    private function cetico(string $titulo, string $lead, string $corpo, string $fonte = ''): array
    {
        $blocoFonte = trim($fonte) !== ''
            ? "TEXTO-FONTE (o ato/release original — use isto pra checar grounding; nada fora daqui pode estar no rascunho como fato confirmado):\n".trim($fonte)
            : 'TEXTO-FONTE: (não fornecido nesta chamada — NÃO marque nenhum tell de grounding, pois você não tem como checar; avalie só forma.)';

        $prompt = <<<PROMPT
Você é um CÉTICO revisando um rascunho de notícia do Jornal Razão (SC) antes do
envio. Sua função é achar "cara de IA" e falhas de apuração REAIS — não é pra
sempre achar alguma coisa. Se o texto estiver limpo, a resposta certa é listas
vazias e nota 10. Regra de ouro: só marque um tell se você conseguir CITAR o
trecho exato do rascunho que tem o problema; "acho que pode ter algo" não
conta. Não invente problema pra ter o que reportar.

Marque como TELL GRAVE (peso 3) — cite o trecho exato do rascunho em cada um:
- Fecho-resumo/moral-da-história genérico e vago (tipo "a ação reforça o
  compromisso da administração com..."). NÃO é isso: fechar contando um
  PRÓXIMO PASSO ou STATUS concreto e específico (quem foi notificado, o que
  muda a partir de quando, prazo) — isso é correto, não marque.
- Frase circular que literalmente não acrescenta nenhum fato novo (repete o
  que já foi dito com outras palavras). NÃO é isso: uma frase que traz um
  fato adicional específico (nome, valor, decisão, prazo) mesmo que feche o
  texto.
- GROUNDING: alguma afirmação do corpo/lead que NÃO aparece no TEXTO-FONTE
  abaixo (fato, número, nome, causa inventados)? Cite a frase do rascunho E
  explique o que no texto-fonte ela contradiz ou extrapola. Paráfrase do
  mesmo fato NÃO é violação de grounding.
- GUARDA SIMÉTRICA: alguma afirmação NÃO confirmada por conta própria (é
  alegação/versão de alguém, não fato objetivo do ato) aparecendo SEM
  atribuição a fonte no mesmo trecho E SEM futuro do pretérito ("teria",
  "estaria")? Fato objetivo relatado em indicativo (ex. "o TCE decidiu",
  "o inquérito foi instaurado") NÃO precisa de futuro do pretérito.
- Detalhe concreto (endereço/telefone/nome/horário/número) que está no
  TEXTO-FONTE mas foi parar só em checklist/lacunas em vez do corpo.

Marque como TELL LEVE (peso 1) — cite o trecho:
- Voz passiva sem agente quando o ato permitiria voz ativa.
- Sigla sem glosa na 1ª menção (só se a sigla realmente aparece sem explicação).
- Adjetivação sensacionalista.

{$blocoFonte}

TÍTULO: {$titulo}
LEAD: {$lead}
CORPO:
{$corpo}

Responda APENAS com JSON, sem markdown, exatamente com estes 3 campos (listas
de STRINGS — cada string cita o trecho problemático entre aspas + explica em
poucas palavras; liste [] se não achou nenhum tell daquele peso — vazio é uma
resposta válida e esperada quando o texto está limpo):
{"nota": <10 menos 3 por tell grave menos 1 por tell leve, mínimo 0>, "tells_graves": ["\\"trecho citado\\" — problema", "..."], "tells_leves": ["\\"trecho citado\\" — problema", "..."]}
PROMPT;

        $out = $this->juiz->completarJson($prompt, 'tell_check', 1);
        $r = $out[0] ?? null;
        if (! is_array($r)) {
            return ['nota' => 10, 'tells' => []];
        }

        $graves = (array) ($r['tells_graves'] ?? []);
        $leves = (array) ($r['tells_leves'] ?? []);
        $tells = [];
        foreach ($graves as $t) {
            $tells[] = ['tell' => (string) $t, 'grave' => true];
        }
        foreach ($leves as $t) {
            $tells[] = ['tell' => (string) $t, 'grave' => false];
        }

        // Fórmula FIXA calculada aqui (não confiar na aritmética do modelo):
        // nota = 10 − 3×tell_grave − 1×tell_leve, mínimo 0.
        $nota = max(0, 10 - 3 * count($graves) - 1 * count($leves));

        return [
            'nota' => $nota,
            'tells' => $tells,
        ];
    }
}
