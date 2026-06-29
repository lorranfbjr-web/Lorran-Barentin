<?php

namespace App\Services\Jr;

use Illuminate\Support\Facades\DB;

/**
 * MESA DE PAUTA — Fase 5. Gera um RASCUNHO no padrão Jornal Razão a partir de um
 * ato do Radar Cívico (DOM/Câmara/MPSC/TCE). Reusa o driver LLM do pipeline
 * (JuizLlm::completarJson, Opus) — NÃO toca o prompt do juiz nem prompt_versao.
 *
 * O ato cívico é um FATO + LEAD pra apurar, não uma matéria pronta: o rascunho é
 * um PONTO DE PARTIDA (título/lead/corpo-base + checklist do que falta apurar),
 * sóbrio e NÃO acusatório, citando a fonte oficial. NÃO publica nada. ISOLADO.
 */
class RascunhoCivico
{
    private const TABELAS = [
        'dom' => 'jr_dom_atos',
        'camara' => 'jr_camara_proposicoes',
        'mpsc' => 'jr_mpsc_extratos',
        'tce' => 'jr_tce_decisoes',
    ];

    private const FONTE_NOME = [
        'dom' => 'Diário Oficial dos Municípios de SC (DOM/SC)',
        'camara' => 'Câmara Municipal (portal SAPL)',
        'mpsc' => 'Ministério Público de SC (DOE-MPSC)',
        'tce' => 'Tribunal de Contas de SC (DOTC-e)',
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
     */
    public function gerar(string $atoRef): array
    {
        $ato = $this->carregar($atoRef);
        if (! $ato) {
            return [];
        }

        $modelo = (string) config('radar_civico.rascunho.modelo', 'claude-opus-4-8');
        $prompt = $this->prompt($ato);
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

    /** Texto pronto pra WhatsApp (e pra mostrar na Mesa). */
    public function formatar(array $r, array $ctx = []): string
    {
        $linhas = [];
        $linhas[] = '📝 RASCUNHO — revisar antes de publicar';
        if (! empty($ctx['municipio'])) {
            $linhas[] = '📍 ' . $ctx['municipio'] . (! empty($ctx['fonte_nome']) ? ' · ' . $ctx['fonte_nome'] : '');
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
                $linhas[] = '• ' . $c;
            }
            $linhas[] = '';
        }
        if (! empty($ctx['url_fonte'])) {
            $linhas[] = '🔗 Fonte: ' . $ctx['url_fonte'];
        }
        $linhas[] = '';
        $linhas[] = '⚠️ Gerado do ato oficial — FATO + LEAD, não acusação. Confira tudo.';

        return trim(implode("\n", $linhas));
    }

    private function prompt(array $ato): string
    {
        $apurar = empty($ato['apurar']) ? '(nada listado)' : '- ' . implode("\n- ", $ato['apurar']);
        $ctx = [
            'Fonte oficial: ' . $ato['fonte_nome'],
            'Município: ' . ($ato['municipio'] ?: '—'),
            'Órgão: ' . ($ato['orgao'] ?: '—'),
            'Objeto: ' . ($ato['objeto'] ?: '—'),
            'Gancho de pauta: ' . ($ato['gancho'] ?: '—'),
            'Ângulo sugerido: ' . ($ato['angulo'] ?: '—'),
            'Texto integral do ato (fonte):',
            trim((string) ($ato['texto_bruto'] ?: '—')),
            'Pontos a apurar já mapeados:',
            $apurar,
        ];
        $bloco = implode("\n", $ctx);

        return <<<PROMPT
Você é repórter do Jornal Razão (jornalismo local sério de Santa Catarina). A
partir de um ATO OFICIAL público abaixo, escreva um RASCUNHO de matéria — um
PONTO DE PARTIDA pro repórter, não um texto pronto pra publicar.

REGRAS (inegociáveis):
- FATO + LEAD: relate só o que o ato oficial diz; o que ainda precisa de apuração
  entra como pergunta/checklist, NUNCA como afirmação. NADA de acusação: um
  inquérito/representação/decisão é um FATO processual, não condenação.
- Tom sóbrio, direto, sem adjetivação sensacionalista. Português do Brasil.
- Cite a fonte oficial no corpo (ex.: "segundo o Ministério Público…", "consta no
  Diário Oficial…"). Não invente nomes, valores, datas ou falas que não estejam no ato.
- Se o ato é raso (só uma intimação/extrato), deixe o corpo curto e jogue o resto
  no checklist — não encha linguiça.

ATO:
{$bloco}

Responda APENAS com um array JSON de UM objeto, sem comentários, exatamente assim:
[{"titulo":"...","lead":"... (1 parágrafo, o lide)","corpo":"... (2 a 4 parágrafos, \\n entre eles)","checklist":["o que apurar 1","o que apurar 2","..."]}]
PROMPT;
    }
}
