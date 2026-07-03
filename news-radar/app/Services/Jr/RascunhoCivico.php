<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\DB;

/**
 * MESA DE PAUTA — Fase 5. Gera um RASCUNHO no padrão Jornal Razão a partir de um
 * ato do Radar Cívico (DOM/Câmara/MPSC/TCE). Reusa o driver LLM do pipeline
 * (JuizLlm::completarJson, Opus) — NÃO toca o prompt do juiz nem prompt_versao.
 *
 * BLOCO revisao-raspagem (03/07): persona resolvida — o CORPO é publicável,
 * pronto pra sair como está; o CHECKLIST é o único espaço de "ainda falta
 * apurar". Sóbrio e NÃO acusatório, citando a fonte oficial. NÃO publica
 * nada. ISOLADO.
 */
class RascunhoCivico
{
    private const TABELAS = [
        'dom' => 'jr_dom_atos',
        'camara' => 'jr_camara_proposicoes',
        'mpsc' => 'jr_mpsc_extratos',
        'tce' => 'jr_tce_decisoes',
        'prefeitura' => 'jr_prefeitura_noticias',
    ];

    private const FONTE_NOME = [
        'dom' => 'Diário Oficial dos Municípios de SC (DOM/SC)',
        'camara' => 'Câmara Municipal (portal SAPL)',
        'mpsc' => 'Ministério Público de SC (DOE-MPSC)',
        'tce' => 'Tribunal de Contas de SC (DOTC-e)',
        'prefeitura' => 'Notícia institucional da Prefeitura (release oficial)',
    ];

    public function __construct(private JuizLlm $juiz) {}

    /** Carrega o ato pela ref "source:id". Devolve [] se não achar. */
    public function carregar(string $atoRef): array
    {
        [$source, $id] = array_pad(explode(':', $atoRef, 2), 2, null);
        $tabela = self::TABELAS[$source] ?? null;
        if (! $tabela || ! ctype_digit((string) $id)) {
            return [];
        }
        $row = DB::table($tabela)->where('id', (int) $id)->first();
        if (! $row) {
            return [];
        }

        $a = (array) $row;

        return [
            'source' => $source,
            'fonte_nome' => self::FONTE_NOME[$source] ?? $source,
            'municipio' => $a['municipio'] ?? null,
            'orgao' => $a['orgao'] ?? $a['unidade_gestora'] ?? null,
            'objeto' => $a['objeto_limpo'] ?? $a['objeto'] ?? $a['assunto'] ?? $a['ementa'] ?? null,
            'gancho' => $a['gancho'] ?? null,
            'angulo' => $a['angulo_sugerido'] ?? null,
            'apurar' => json_decode($a['o_que_apurar'] ?? '[]', true) ?: [],
            'texto_bruto' => $a['texto_bruto'] ?? null,
            'data_pub' => $a['data_pub'] ?? null,
            'url_fonte' => $a['url_fonte'] ?? null,
        ];
    }

    /**
     * Gera o rascunho. Devolve ['titulo','lead','corpo','checklist'(array)] ou [].
     *
     * @param  array<int,string>|null  $evitar  O5 (tell-check): tells da tentativa anterior a evitar na regeneração.
     */
    public function gerar(string $atoRef, ?array $evitar = null): array
    {
        $ato = $this->carregar($atoRef);
        if (! $ato) {
            return [];
        }

        $modelo = (string) config('radar_civico.rascunho.modelo', 'claude-opus-4-8');
        $prompt = $this->prompt($ato);
        if (! empty($evitar)) {
            $prompt .= "\n\nREGERE evitando estes problemas encontrados na tentativa anterior:\n- ".implode("\n- ", $evitar);
        }
        $out = $this->juiz->completarJson($prompt, 'rascunho_civico', 1, $modelo);

        // completarJson devolve array; pode vir [{...}] ou {...}
        $r = $out[0] ?? $out;
        if (! is_array($r) || empty($r['titulo'])) {
            return [];
        }

        return [
            'titulo' => trim((string) ($r['titulo'] ?? '')),
            'lead' => trim((string) ($r['lead'] ?? '')),
            'corpo' => trim((string) ($r['corpo'] ?? '')),
            'checklist' => array_values(array_filter(array_map(
                fn ($x) => trim((string) $x),
                (array) ($r['checklist'] ?? $ato['apurar'])
            ))),
        ];
    }

    /**
     * BLOCO 8a — formato LIMPO canônico do grupo (AUTO e Mesa): SÓ conteúdo
     * publicável (título forte + linha fina + corpo + crédito REAL da foto
     * quando ela foi anexada) e, após o separador, UMA linha operacional.
     * Metadados (gate/checklist/fonte) NUNCA entram aqui — moram no banco/Mesa.
     *
     * @param  ?array  $foto  saída do FotoOficial (ou null = fonte sem foto)
     * @param  bool  $fotoAnexada  send-image confirmou? (crédito órfão nunca)
     * @param  string  $origem  rótulo da linha operacional (auto-rascunho | rascunho da Mesa)
     */
    public function formatarLimpo(array $r, ?array $foto, bool $fotoAnexada, string $origem = 'auto-rascunho', ?string $tellFlag = null): string
    {
        $blocos = ['*'.trim($r['titulo']).'*'];
        if (trim((string) $r['lead']) !== '') {
            $blocos[] = '_'.trim($r['lead']).'_';
        }
        if (trim((string) $r['corpo']) !== '') {
            $blocos[] = trim($r['corpo']);
        }
        if ($foto !== null && $fotoAnexada) {
            $blocos[] = 'Foto: '.$foto['credito'];
        }

        $operacional = "🤖 {$origem} · ✅ cria draft no WP · ❌ descarta";
        if ($foto === null) {
            $operacional .= ' · 📷 sem foto oficial';
        } elseif (! $fotoAnexada) {
            $operacional .= ' · 📷 foto vai no draft (falhou ao anexar aqui)';
        }
        // O5 (tell-check): NUNCA segura/descarta — só informa quem decide.
        if ($tellFlag !== null && $tellFlag !== '') {
            $operacional .= " · ⚠ tell:{$tellFlag}";
        }

        return implode("\n\n", $blocos)."\n───\n".$operacional;
    }

    /** Texto ANOTADO da Mesa/fila (interno — nunca vai pro grupo). */
    public function formatar(array $r, array $ctx = []): string
    {
        $linhas = [];
        $linhas[] = '📝 RASCUNHO — revisar antes de publicar';
        if (! empty($ctx['municipio'])) {
            $linhas[] = '📍 '.$ctx['municipio'].(! empty($ctx['fonte_nome']) ? ' · '.$ctx['fonte_nome'] : '');
        }
        $linhas[] = '';
        $linhas[] = $r['titulo'];
        $linhas[] = '';
        if ($r['lead'] !== '') {
            $linhas[] = $r['lead'];
            $linhas[] = '';
        }
        if ($r['corpo'] !== '') {
            $linhas[] = $r['corpo'];
            $linhas[] = '';
        }
        if (! empty($r['checklist'])) {
            $linhas[] = '✅ Apurar antes de fechar:';
            foreach ($r['checklist'] as $c) {
                $linhas[] = '• '.$c;
            }
            $linhas[] = '';
        }
        if (! empty($ctx['url_fonte'])) {
            $linhas[] = '🔗 Fonte: '.$ctx['url_fonte'];
        }
        $linhas[] = '';
        $linhas[] = '⚠️ Gerado do ato oficial — FATO + LEAD, não acusação. Confira tudo.';

        return trim(implode("\n", $linhas));
    }

    private function prompt(array $ato): string
    {
        $apurar = empty($ato['apurar']) ? '(nada listado)' : '- '.implode("\n- ", $ato['apurar']);
        $ctx = [
            'Fonte oficial: '.$ato['fonte_nome'],
            'Município: '.($ato['municipio'] ?: '—'),
            'Órgão: '.($ato['orgao'] ?: '—'),
            'Objeto: '.($ato['objeto'] ?: '—'),
            'Data de publicação do ato (data_referencia — use SÓ esta pra ancorar dia da semana; nunca chute): '.($ato['data_pub'] ?: '—'),
            'Gancho de pauta: '.($ato['gancho'] ?: '—'),
            'Ângulo sugerido: '.($ato['angulo'] ?: '—'),
            'Texto integral do ato (fonte):',
            trim((string) ($ato['texto_bruto'] ?: '—')),
            'Pontos a apurar já mapeados:',
            $apurar,
        ];
        $bloco = implode("\n", $ctx);

        return <<<PROMPT
Você é repórter do Jornal Razão (jornalismo local sério de Santa Catarina). A
partir de um ATO OFICIAL público abaixo, escreva um RASCUNHO no padrão JR.

PERSONA (não confunda os dois campos): o CORPO é texto PUBLICÁVEL, pronto pra
sair como está — nada de bastidor, nada de "isso ainda precisa apurar" dentro
dele. O CHECKLIST é o único lugar onde vive o que falta apurar. Um vira o
outro nunca.

FATO VS VERSÃO (inegociável, vale mais que qualquer regra de estilo abaixo):
- Relate só o que o ato oficial diz. Nada de acusação: inquérito/representação/
  decisão é FATO processual, não condenação.
- Atribua ao órgão sempre que a informação não for fato confirmado por conta
  própria ("segundo o Ministério Público…", "conforme decisão do TCE…",
  "informou a Prefeitura…") — mas NUNCA cite o documento em si ("segundo o
  BO/protocolo/processo nº…"); cite a INSTITUIÇÃO, não o papel.
- Gramática da incerteza: confirmado = indicativo; versão de alguém = futuro do
  pretérito ("teria fugido", "estaria à frente") — isso já marca que é versão,
  sem precisar de fórmula de atribuição repetida.
- GUARDA SIMÉTRICA (tell grave, na direção contrária): toda afirmação não
  confirmada por conta própria tem que ter OU atribuição a uma fonte no mesmo
  parágrafo OU futuro do pretérito. Frase sem nenhum dos dois = alegação
  desatribuída — reprovado, mesmo que pareça "mais natural".
- Se a fonte é release/notícia institucional (prefeitura, assessoria): é
  VERSÃO OFICIAL de parte interessada — sinalize isso ("segundo a
  Prefeitura…") e mande ouvir o outro lado / checar o dado pro checklist.
- Nunca invente nome, valor, data, fala ou causa que não esteja no ato. Zero
  métrica de engajamento. Nomes sempre completos.

FORMATO — mate estes vícios um a um (são os erros reais que já saíram daqui):
1. NUNCA repita informação: linha fina/lead completam o título sem repeti-lo;
   o corpo NUNCA reabre o que o lead já disse. Cada parágrafo soma um fato do
   ATO ainda não usado. Ato raso ⇒ corpo CURTO é a primeira opção, nunca
   esticar com reformulação do mesmo fato.
2. No corpo (lead não conta), NUNCA emende 2 parágrafos abrindo os dois com
   fórmula de atribuição ("Segundo…", "De acordo com…", "A administração
   informou…"). Varie: fato→fonte é mais natural que fonte→fato ("O rio
   Itajaí-Mirim chegou a 3,73 metros, segundo a Defesa Civil"). Variar a
   abertura NUNCA pode significar remover a atribuição (ver guarda simétrica).
3. Parágrafos de tamanho VARIADO — nunca 3 parágrafos-clichê do mesmo tamanho
   em que um só reafirma o título. Voz ativa com sujeito concreto, nunca
   passiva sem agente ("o local foi definido" → diga quem definiu, se o ato
   disser). Ato raso = 1-2 parágrafos; ato rico = 4-6. Tamanho é resultado do
   ato, não uma meta a bater.
4. Toda frase passa no teste "o leitor sabe algo que não sabia antes dela?".
   Corte frase-enchimento/abstração vaga ("em meio a…", "diante de…", "e ocorre
   enquanto a administração mantém o atendimento" — isso não diz nada).
5. NUNCA fale SOBRE o release ou o canal de divulgação — narre o FATO.
   Exemplo NEGATIVO real (não escreva assim): "A administração municipal
   divulgou a informação por meio de notícia institucional sobre o
   encerramento do atendimento descentralizado." Isso é vazamento de
   bastidor, não notícia.
6. REGRA DE OURO — dado concreto que JÁ ESTÁ no ato (endereço, telefone, nome,
   horário, número) vai NO CORPO. O checklist é só pro que NÃO está no ato
   (outro lado, confirmação, contexto a apurar). Checklist com dado que já
   está no texto-fonte é erro grave de formato.
7. Data: 1ª menção no corpo = dia da semana + dia entre parênteses, ex.
   "sexta-feira (3)", consistente no texto todo; use a data_referencia do
   contexto acima pra ancorar a conversão — NUNCA chute. Título sem dêixis
   relativa ("nesta sexta") — se não der pra ancorar com segurança, mantenha
   o formato exato da fonte e jogue "confirmar a data" no checklist.
8. Se o ato nomeia lugar (bairro, rua, ponto), o corpo nomeia — hiperlocal é o
   diferencial do jornal. Se o ato NÃO nomeia, não invente: checklist
   ("apurar bairro/local exato").
9. Sigla na 1ª menção ganha glosa curta ("CEJA, o centro de educação de jovens
   e adultos").

PROIBIDO sempre: "além disso", "vale ressaltar/destacar", "nesse sentido",
"não apenas X, mas também Y", "reforça o compromisso"; fecho-resumo genérico
(troque por status/próximo passo real, se o ato tiver); travessão (—) como
recurso estilístico; ponto final no título; tradução de inglês ("residentes",
"oficiais"); qualquer coisa inventada pra "dar vida" ao texto.

EXEMPLO BOM (nível de concretude quando o ato fornece — não copie a forma,
copie o padrão de usar o que o ato dá):
"A Secretaria da Pessoa Idosa de Balneário Camboriú oferece, de graça, uma
oficina de xadrez para os idosos do município. As aulas acontecem às segundas
e quartas-feiras, das 15h às 17h, na sede da secretaria, na Rua 1822, no
Centro, e as inscrições estão abertas.\n\nSegundo o secretário da Pessoa
Idosa, Claudir Maciel, o xadrez estimula a memória e a socialização em
qualquer idade..." — endereço, nome e horário JÁ estavam no ato e foram pro
corpo, não pro checklist.

CONTRA-EXEMPLO — NÃO ESCREVA ASSIM (mesmo ato do teste de calibração, os tells
estão marcados entre colchetes, não copie os colchetes nem a estrutura):
"Segundo a Prefeitura de Itajaí, o mutirão [abre com fórmula de atribuição]...
A ação faz parte do mutirão de castração gratuita organizado pelo
município... [parágrafo do meio só reafirma o lead, não soma fato novo] O
local foi definido após alteração na programação, segundo a administração
municipal. [passiva sem agente + fecho vago que não diz nada de novo]"

ATO:
{$bloco}

Responda APENAS com um array JSON de UM objeto, sem comentários, exatamente assim:
[{"titulo":"...","lead":"... (1 parágrafo, o lide)","corpo":"... (parágrafos de tamanho variado conforme o ato, \\n entre eles)","checklist":["só o que NÃO está no ato","..."]}]
PROMPT;
    }
}
