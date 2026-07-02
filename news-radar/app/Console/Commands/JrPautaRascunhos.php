<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Cérebro editorial" do Jornal Razão (demonstração).
 *
 * A reescrita NÃO usa API externa de LLM: os rascunhos abaixo foram redigidos
 * manualmente (pelo Claude Code, dentro do goal) a partir do texto REAL de cada
 * release em jr_pauta_capturas, seguindo o DNA Jornal Razão:
 *  - só fatos do release; nada inventado (faltou dado -> "lacunas");
 *  - confirmado x versão ("segundo", "conforme", "de acordo com");
 *  - sem aspas sem fala literal real atribuída;
 *  - perícia em SC = "Polícia Científica"; PMSC tratada de forma objetiva.
 *
 * O comando só LÊ jr_pauta_capturas (para confirmar a origem) e grava em
 * jr_pauta_rascunhos (tabela NOVA). Idempotente por captura_message_id.
 * Não publica, não envia, não chama WordPress/Z-API.
 */
class JrPautaRascunhos extends Command
{
    protected $signature = 'jrpauta:rascunhos {--print-only : Só imprime o que já está gravado, sem regravar} {--html= : Exporta um HTML legível para o caminho informado (ou storage/app/rascunhos.html)}';

    protected $description = 'Gera/grava rascunhos editoriais (DNA Jornal Razão) a partir de releases reais de jr_pauta_capturas e imprime para avaliação. Não publica nem envia nada.';

    public function handle(): int
    {
        if (! $this->option('print-only')) {
            $rascunhos = $this->rascunhos();
            $now = Carbon::now();
            $faltando = [];

            foreach ($rascunhos as $r) {
                // Só LEITURA da captura, para confirmar que o release existe.
                $cap = DB::table('jr_pauta_capturas')
                    ->where('message_id', $r['captura_message_id'])->first();
                if (! $cap) {
                    $faltando[] = $r['captura_message_id'];
                    continue;
                }

                DB::table('jr_pauta_rascunhos')->upsert([[
                    'captura_message_id' => $r['captura_message_id'],
                    'cidade'             => $r['cidade'],
                    'fonte'              => $r['fonte'],
                    'temperatura'        => $r['temperatura'],
                    'confianca'          => $r['confianca'],
                    'titulos'            => json_encode($r['titulos'], JSON_UNESCAPED_UNICODE),
                    'linha_fina'         => $r['linha_fina'],
                    'materia'            => $r['materia'],
                    'tags'               => json_encode($r['tags'], JSON_UNESCAPED_UNICODE),
                    'lacunas'            => json_encode($r['lacunas'], JSON_UNESCAPED_UNICODE),
                    'gerado_em'          => $now,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]], ['captura_message_id'], [
                    'cidade', 'fonte', 'temperatura', 'confianca', 'titulos',
                    'linha_fina', 'materia', 'tags', 'lacunas', 'gerado_em', 'updated_at',
                ]);
            }

            if ($faltando) {
                $this->warn('Capturas não encontradas (não gravadas): ' . implode(', ', $faltando));
            }
            $this->info('Rascunhos gravados/atualizados: ' . (count($rascunhos) - count($faltando)));
        }

        if ($this->option('html') !== null) {
            $destino = $this->option('html') ?: storage_path('app/rascunhos.html');
            $this->exportarHtml($destino);
            $this->info('HTML gerado em: ' . $destino);
            $this->line('Abra com duplo-clique ou: xdg-open "' . $destino . '"');
            return self::SUCCESS;
        }

        $this->imprimir();
        return self::SUCCESS;
    }

    private function exportarHtml(string $destino): void
    {
        $rows = DB::table('jr_pauta_rascunhos')->orderBy('id')->get();
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $resumo = DB::table('jr_pauta_rascunhos')
            ->select('temperatura', 'confianca', DB::raw('count(*) c'))
            ->groupBy('temperatura', 'confianca')->orderBy('temperatura')->get();

        $cards = '';
        foreach ($rows as $i => $r) {
            $titulos = json_decode($r->titulos, true) ?: [];
            $tags    = json_decode($r->tags, true) ?: [];
            $lacunas = json_decode($r->lacunas, true) ?: [];

            $titulosHtml = '';
            foreach ($titulos as $n => $t) {
                $titulosHtml .= '<li>' . $e($t) . '</li>';
            }
            $blocosHtml = '';
            foreach (explode("\n", $r->materia) as $linha) {
                $linha = trim($linha);
                if ($linha === '') { continue; }
                if (preg_match('/^\(\d\)\s/', $linha)) {
                    $blocosHtml .= '<h4>' . $e($linha) . '</h4>';
                } else {
                    $blocosHtml .= '<p>' . $e($linha) . '</p>';
                }
            }
            $tagsHtml = '';
            foreach ($tags as $t) {
                $tagsHtml .= '<span class="tag">' . $e($t) . '</span>';
            }
            $lacunasHtml = '';
            foreach ($lacunas as $l) {
                $lacunasHtml .= '<li>' . $e($l) . '</li>';
            }

            $temp = $r->temperatura;
            $conf = $r->confianca;

            $cards .= '
            <article class="card ' . $e($temp) . '">
              <header>
                <div class="num">#' . sprintf('%02d', $i + 1) . '</div>
                <div class="meta">
                  <h2>' . $e($titulos[0] ?? '(sem título)') . '</h2>
                  <div class="src">' . $e($r->fonte) . ' &middot; ' . $e($r->cidade ?? 'cidade não informada') . '</div>
                </div>
                <div class="badges">
                  <span class="badge t-' . $e($temp) . '">' . $e(strtoupper($temp)) . '</span>
                  <span class="badge c-' . $e($conf) . '">confiança ' . $e($conf) . '</span>
                </div>
              </header>
              <p class="lf"><strong>Linha-fina:</strong> ' . $e($r->linha_fina) . '</p>
              <details open><summary>Títulos (' . count($titulos) . ')</summary><ol>' . $titulosHtml . '</ol></details>
              <details open><summary>Matéria</summary><div class="materia">' . $blocosHtml . '</div></details>
              <div class="tags">' . $tagsHtml . '</div>
              <details><summary>Lacunas (' . count($lacunas) . ')</summary><ul class="lacunas">' . $lacunasHtml . '</ul></details>
              <div class="capid">captura: ' . $e($r->captura_message_id) . '</div>
            </article>';
        }

        $resumoHtml = '';
        foreach ($resumo as $g) {
            $resumoHtml .= '<span class="rb">' . $e($g->temperatura) . ' / ' . $e($g->confianca) . ': <b>' . $g->c . '</b></span>';
        }

        $html = '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Rascunhos — Cérebro Jornal Razão</title>
        <style>
          :root{--q:#d64545;--f:#2f6fb0;--bg:#f4f5f7;--card:#fff;--line:#e3e6ea;--ink:#1f2933;--mut:#6b7280}
          *{box-sizing:border-box}
          body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 -apple-system,Segoe UI,Roboto,Arial,sans-serif}
          .wrap{max-width:900px;margin:0 auto;padding:24px 16px 64px}
          .top{position:sticky;top:0;background:var(--bg);padding:14px 0;border-bottom:1px solid var(--line);z-index:5}
          .top h1{margin:0 0 6px;font-size:20px}
          .resumo{display:flex;gap:8px;flex-wrap:wrap;font-size:13px;color:var(--mut)}
          .rb{background:#fff;border:1px solid var(--line);border-radius:999px;padding:2px 10px}
          .card{background:var(--card);border:1px solid var(--line);border-left:6px solid var(--mut);border-radius:12px;padding:18px;margin:18px 0;box-shadow:0 1px 3px rgba(0,0,0,.05)}
          .card.quente{border-left-color:var(--q)} .card.fria{border-left-color:var(--f)}
          .card header{display:flex;gap:12px;align-items:flex-start}
          .num{font-weight:700;color:var(--mut);font-size:14px;padding-top:4px}
          .meta{flex:1} .meta h2{margin:0;font-size:18px;line-height:1.3}
          .src{color:var(--mut);font-size:13px;margin-top:4px}
          .badges{display:flex;flex-direction:column;gap:6px;align-items:flex-end}
          .badge{font-size:11px;font-weight:700;letter-spacing:.03em;border-radius:999px;padding:3px 10px;white-space:nowrap}
          .t-quente{background:#fde2e2;color:var(--q)} .t-fria{background:#e1edf8;color:var(--f)}
          .c-alta{background:#e3f6e8;color:#1f7a44} .c-media{background:#fcefd6;color:#9a6b00} .c-baixa{background:#f0e2f6;color:#7a2f9a}
          .lf{background:#fafbfc;border:1px solid var(--line);border-radius:8px;padding:10px 12px;font-size:15px}
          details{margin:12px 0;border-top:1px dashed var(--line);padding-top:8px}
          summary{cursor:pointer;font-weight:600;font-size:14px;color:#374151}
          ol{margin:8px 0 0;padding-left:22px} ol li{margin:3px 0}
          .materia h4{margin:14px 0 2px;font-size:13px;color:var(--mut);text-transform:uppercase;letter-spacing:.04em}
          .materia p{margin:4px 0}
          .tags{margin:14px 0 4px;display:flex;gap:6px;flex-wrap:wrap}
          .tag{background:#eef1f4;border-radius:6px;padding:3px 9px;font-size:12px;color:#374151}
          .lacunas{margin:8px 0 0;padding-left:22px;color:#8a4b00}
          .capid{margin-top:12px;font-size:11px;color:#aab2bd;font-family:ui-monospace,monospace}
          @media print{.top{position:static} details{open:true}}
        </style></head><body><div class="wrap">
        <div class="top"><h1>Rascunhos — Cérebro Jornal Razão <small style="color:var(--mut);font-weight:400">(' . $rows->count() . ' itens · releases reais)</small></h1>
        <div class="resumo">' . $resumoHtml . '</div></div>'
        . $cards .
        '</div></body></html>';

        $dir = dirname($destino);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($destino, $html);
    }

    private function imprimir(): void
    {
        $rows = DB::table('jr_pauta_rascunhos')->orderBy('id')->get();
        $this->newLine();
        $this->line('################################################################');
        $this->line('#   RASCUNHOS EDITORIAIS — CÉREBRO JORNAL RAZÃO (avaliação)     #');
        $this->line('#   Total: ' . $rows->count() . '   (origem: jr_pauta_capturas, releases reais)   #');
        $this->line('################################################################');

        foreach ($rows as $i => $r) {
            $titulos = json_decode($r->titulos, true) ?: [];
            $tags    = json_decode($r->tags, true) ?: [];
            $lacunas = json_decode($r->lacunas, true) ?: [];

            $this->newLine();
            $this->line('================================================================');
            $this->line(sprintf('RASCUNHO %02d/%02d', $i + 1, $rows->count()));
            $this->line('----------------------------------------------------------------');
            $this->line('Fonte........: ' . $r->fonte);
            $this->line('Cidade.......: ' . ($r->cidade ?? '(não informada)'));
            $this->line('Captura ID...: ' . $r->captura_message_id);
            $this->line('Temperatura..: ' . strtoupper($r->temperatura) . '   |   Confiança: ' . strtoupper($r->confianca));
            $this->newLine();
            $this->line('LINHA-FINA:');
            $this->line('  ' . $r->linha_fina);
            $this->newLine();
            $this->line('TÍTULOS (' . count($titulos) . '):');
            foreach ($titulos as $n => $t) {
                $this->line(sprintf('  %2d. %s', $n + 1, $t));
            }
            $this->newLine();
            $this->line('MATÉRIA:');
            foreach (explode("\n", $r->materia) as $linha) {
                $this->line('  ' . $linha);
            }
            $this->newLine();
            $this->line('TAGS (' . count($tags) . '): ' . implode(' · ', $tags));
            $this->newLine();
            $this->line('LACUNAS (' . count($lacunas) . '):');
            foreach ($lacunas as $l) {
                $this->line('  - ' . $l);
            }
        }
        $this->newLine();
        $this->line('================================================================');
        $this->line('Resumo por temperatura/confiança:');
        foreach (DB::table('jr_pauta_rascunhos')
                    ->select('temperatura', 'confianca', DB::raw('count(*) c'))
                    ->groupBy('temperatura', 'confianca')
                    ->orderBy('temperatura')->get() as $g) {
            $this->line(sprintf('  %-7s | %-6s : %d', $g->temperatura, $g->confianca, $g->c));
        }
        $this->line('================================================================');
    }

    /** Bloco 5-partes formatado. */
    private function materia(string $lead, string $fonte, string $cena, string $tempo, string $status): string
    {
        return implode("\n", [
            '(1) LEAD', $lead, '',
            '(2) FONTE E ATRIBUIÇÃO', $fonte, '',
            '(3) CENA', $cena, '',
            '(4) LINHA DO TEMPO', $tempo, '',
            '(5) STATUS E PRÓXIMOS PASSOS', $status,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function rascunhos(): array
    {
        return [

            // ---------------------------------------------------------------- 1
            [
                'captura_message_id' => '3A2A61B83BF8AF892AB8',
                'cidade' => 'Itapema',
                'fonte' => 'Polícia Militar (31º BPM)',
                'temperatura' => 'quente',
                'confianca' => 'alta',
                'titulos' => [
                    'Polícia Militar prende suspeito de tráfico de drogas em Itapema',
                    'Homem é preso com 22 porções de cocaína no Alto São Bento, em Itapema',
                    'PM apreende cocaína fracionada e dinheiro em kitnet de Itapema',
                    'Suspeito de tráfico é detido durante patrulhamento em Itapema',
                    'Cocaína pronta para venda é apreendida em ação da PM em Itapema',
                    'Polícia Militar encontra drogas e R$ 871 em kitnet no Alto São Bento',
                    'Patrulhamento da PM termina em prisão por tráfico em Itapema',
                    'Odor de maconha leva PM a kitnet e termina em prisão em Itapema',
                    'PM apreende 21 gramas de cocaína em 22 porções em Itapema',
                    'Suspeito é encaminhado à Polícia Civil após apreensão de drogas em Itapema',
                    'Ação da PM tira drogas de circulação no Alto São Bento, em Itapema',
                    'Itapema: PM prende suspeito e apreende cocaína fracionada para venda',
                ],
                'linha_fina' => 'Segundo a Polícia Militar, abordagem na noite de sexta-feira (5), no bairro Alto São Bento, terminou com a apreensão de cerca de 21 gramas de cocaína em 22 porções e R$ 871 em dinheiro; suspeito foi encaminhado à Polícia Civil.',
                'materia' => $this->materia(
                    'A Polícia Militar prendeu um suspeito de tráfico de drogas na noite desta sexta-feira (5), no bairro Alto São Bento, em Itapema. Com ele foram apreendidas cerca de 21 gramas de cocaína, divididas em 22 porções, além de R$ 871 em dinheiro. O suspeito foi encaminhado à Polícia Civil.',
                    'As informações são do 31º Batalhão da Polícia Militar (PMSC). Segundo a corporação, a abordagem começou durante patrulhamento pelo bairro. O nome e a idade do suspeito não foram informados.',
                    'De acordo com a PM, a guarnição viu um homem consumindo maconha em via pública, em frente a um conjunto de kitnets. Ele tentou fugir para o pátio das residências, mas foi abordado; com ele havia uma pequena quantidade de entorpecente descrita como para consumo pessoal. Ainda conforme a corporação, durante o atendimento os policiais sentiram forte odor de maconha vindo de outra residência no mesmo terreno e, ao se aproximarem, viram um homem fumando substância semelhante. Na averiguação, segundo a PM, foram localizadas aproximadamente 21 gramas de cocaína fracionadas em 22 porções em embalagens do tipo zip lock, além de embalagens vazias e R$ 871 em moedas e cédulas de pequeno valor.',
                    'Sexta-feira (5), à noite — patrulhamento e primeira abordagem em via pública; na sequência — verificação da kitnet e apreensão da cocaína, do dinheiro e das embalagens; depois — voz de prisão ao suspeito.',
                    'Diante dos indícios de tráfico, segundo a PM, o suspeito recebeu voz de prisão e foi encaminhado à Delegacia de Polícia Civil, junto com as drogas e os materiais apreendidos, para os procedimentos legais. O caso será apurado pela Polícia Civil. A reportagem não teve acesso ao nome do suspeito nem a manifestação da defesa.'
                ),
                'tags' => ['Itapema', 'Costa Verde e Mar', 'segurança pública', 'tráfico de drogas', 'Polícia Militar', 'cocaína', 'apreensão de drogas', 'flagrante'],
                'lacunas' => [
                    'Nome e idade do suspeito não informados',
                    'Horário exato não informado (apenas "noite")',
                    'Delegacia de destino não especificada',
                    'Não há manifestação da defesa',
                    'Antecedentes do suspeito não informados',
                ],
            ],

            // ---------------------------------------------------------------- 2
            [
                'captura_message_id' => '3AA574A50324AFB8137E',
                'cidade' => 'Florianópolis',
                'fonte' => 'Polícia Militar (BOPE/PMSC)',
                'temperatura' => 'quente',
                'confianca' => 'alta',
                'titulos' => [
                    'Confronto com o BOPE deixa um morto e um preso na Costeira, em Florianópolis',
                    'Homem morre e outro é preso após confronto com a PM na Costeira, em Florianópolis',
                    'BOPE apreende pistola e drogas em ação na Costeira, em Florianópolis',
                    'Operação do BOPE termina com um morto, um preso e arma apreendida em Florianópolis',
                    'PM registra confronto na comunidade da Cidade Alta, na Costeira, em Florianópolis',
                    'Ação do BOPE na Costeira resulta em prisão e apreensão de arma e drogas',
                    'Confronto na Costeira: BOPE apreende pistola, munições e entorpecentes',
                    'Um homem morre após troca de tiros com o BOPE em Florianópolis, diz PM',
                    'BOPE prende suspeito e apreende drogas após confronto na Costeira',
                    'Florianópolis: confronto com o BOPE deixa um morto e um detido na Cidade Alta',
                    'PM apreende arma de uso restrito e drogas após confronto na Costeira',
                    'Abordagem do BOPE termina em confronto, morte e prisão na Costeira',
                ],
                'linha_fina' => 'Segundo a Polícia Militar, equipe do BOPE foi recebida a tiros ao abordar dois homens na comunidade da Cidade Alta, na Costeira, na noite de sexta-feira (5); um deles morreu, o outro foi preso e foram apreendidos uma pistola, munições e drogas.',
                'materia' => $this->materia(
                    'Um homem morreu e outro foi preso após um confronto com o Batalhão de Operações Policiais Especiais (BOPE) na noite de sexta-feira (5), por volta das 23h, na comunidade da Cidade Alta, no bairro Costeira, em Florianópolis. Segundo a Polícia Militar, a equipe foi recebida a tiros ao tentar abordar dois suspeitos.',
                    'As informações são do BOPE/PMSC. A corporação classificou a ocorrência como confronto contra guarnição policial, tráfico de drogas e porte ilegal de arma de fogo de uso restrito. O nome dos envolvidos não foi informado.',
                    'Conforme o relato da PM, a guarnição da CATE/BOPE patrulhava a comunidade da Cidade Alta/Costeira — descrita pela corporação como ponto conhecido de tráfico — quando tentou abordar dois homens, que teriam fugido. Ainda segundo a PM, um deles efetuou disparos contra os policiais, e a guarnição reagiu para cessar a agressão. Um dos homens foi atingido e morreu no local; o outro foi localizado e preso. De acordo com a corporação, o suspeito que teria atirado possuía registros policiais por ameaça, tráfico de drogas, receptação, assédio sexual e lesão corporal.',
                    'Sexta-feira (5), por volta das 23h — abordagem e fuga dos suspeitos; em seguida — disparos contra a guarnição e reação policial; na sequência — um homem morto no local e outro preso, com apreensão de arma, munições e drogas.',
                    'Foram apreendidos, segundo a PM: uma pistola Taurus PT92, 13 munições calibre 9 mm, um carregador, 479 g de maconha, 12 g de cocaína, 18 g de crack, cinco comprimidos de ecstasy, uma balança de precisão e R$ 238,60. Casos com morte decorrente de intervenção policial são apurados pela Polícia Civil, com perícia da Polícia Científica. A reportagem não teve acesso à identificação dos envolvidos nem a manifestação de familiares ou da defesa.'
                ),
                'tags' => ['Florianópolis', 'Grande Florianópolis', 'segurança pública', 'confronto policial', 'BOPE', 'tráfico de drogas', 'apreensão de armas', 'morte em intervenção policial'],
                'lacunas' => [
                    'Identidade do homem morto e do preso não informada',
                    'Idade dos envolvidos não informada',
                    'Não há manifestação de familiares nem da defesa',
                    'Resultado da perícia da Polícia Científica não disponível',
                    'A versão sobre o confronto é a da Polícia Militar; não há contraditório',
                ],
            ],

            // ---------------------------------------------------------------- 3
            [
                'captura_message_id' => '3ABF04E6702604522DD4',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Guarda Municipal de Balneário Camboriú',
                'temperatura' => 'quente',
                'confianca' => 'alta',
                'titulos' => [
                    'Guarda Municipal recupera carro furtado em menos de três horas em Balneário Camboriú',
                    'Mercedes furtado é recuperado e dois jovens são presos em Balneário Camboriú',
                    'ROMU encontra carro furtado com dois ocupantes no Bairro das Nações, em BC',
                    'Guarda Municipal de BC recupera Mercedes furtado no Bairro Ariribá',
                    'Dois homens de 18 anos são presos após furto de carro em Balneário Camboriú',
                    'Carro furtado é devolvido ao dono após ação da Guarda Municipal em BC',
                    'Guarda Municipal de BC localiza veículo furtado na Rua Indonésia',
                    'Furto de Mercedes em BC termina com prisão e carro recuperado em três horas',
                    'Central 153 aciona ROMU e recupera carro furtado em Balneário Camboriú',
                    'Balneário Camboriú: Guarda Municipal prende dois e recupera carro furtado',
                    'Veículo furtado no Ariribá é encontrado no Bairro das Nações, em BC',
                    'Ação rápida da Guarda Municipal recupera carro furtado em BC',
                ],
                'linha_fina' => 'Segundo a Guarda Municipal, um Mercedes-Benz furtado no Bairro Ariribá foi localizado menos de três horas depois, no Bairro das Nações; dois homens de 18 anos foram presos e o carro devolvido ao dono.',
                'materia' => $this->materia(
                    'A Guarda Municipal de Balneário Camboriú recuperou um carro furtado em menos de três horas na noite de sexta-feira (5), por volta das 23h40, no Bairro das Nações. Dois homens de 18 anos foram presos e o veículo foi devolvido ao proprietário.',
                    'As informações são da Guarda Municipal de Balneário Camboriú. Segundo a corporação, a Central de Operações 153 recebeu o alerta sobre o furto de um Mercedes-Benz CLA 200, azul, ocorrido menos de três horas antes no Bairro Ariribá. Os nomes dos presos não foram informados.',
                    'De acordo com a Guarda, após o repasse das informações às equipes, uma guarnição da Ronda Ostensiva Municipal (ROMU) localizou o automóvel na Rua Indonésia, no Bairro das Nações, com dois ocupantes. Os dois homens, de 18 anos, foram abordados e presos.',
                    'Sexta-feira (5), à noite — furto do veículo no Bairro Ariribá; menos de três horas depois, por volta das 23h40 — localização do carro no Bairro das Nações; na sequência — prisão dos dois ocupantes e devolução do veículo ao proprietário.',
                    'Segundo a Guarda Municipal, os dois homens foram encaminhados à Central de Plantão Policial (CPP). O carro foi entregue ao dono. A reportagem não teve acesso aos nomes dos presos nem a manifestação da defesa.'
                ),
                'tags' => ['Balneário Camboriú', 'Costa Verde e Mar', 'segurança pública', 'furto de veículo', 'Guarda Municipal', 'ROMU', 'recuperação de veículo', 'Bairro das Nações'],
                'lacunas' => [
                    'Nomes dos presos não informados',
                    'Placa/identificação do veículo não detalhada',
                    'Como o furto ocorreu não foi informado',
                    'Não há manifestação da defesa',
                    'Não informado se houve violência contra o proprietário',
                ],
            ],

            // ---------------------------------------------------------------- 4
            [
                'captura_message_id' => '3A17872E6A8710113FF2',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Prefeitura de Balneário Camboriú (Fundação Cultural)',
                'temperatura' => 'quente',
                'confianca' => 'alta',
                'titulos' => [
                    'Festa Raízes de Taquaras segue até domingo em Balneário Camboriú',
                    '7ª Festa Raízes de Taquaras tem shows, gastronomia e telão do Brasil em BC',
                    'Festa Raízes de Taquaras já vendeu cerca de mil tainhas e churrascos em BC',
                    'Festa de Taquaras tem entrada gratuita e programação até domingo em BC',
                    'Rodovia Interpraias fica interditada até 10 de junho por causa da Festa de Taquaras',
                    'Festa Raízes de Taquaras terá telão do amistoso Brasil x Egito neste sábado em BC',
                    'Pavilhão da Festa de Taquaras cresce 25% e tem 43 barracas em 2026',
                    'Balneário Camboriú: Festa Raízes de Taquaras reúne grande público na Interpraias',
                    'Festa Raízes de Taquaras celebra pesca artesanal e tradições do bairro em BC',
                    'Confira a programação da Festa Raízes de Taquaras neste fim de semana em BC',
                    'Festa de Taquaras tem shows, boi de mamão e gastronomia típica em BC',
                    'Trânsito na Interpraias muda até 10 de junho por causa da Festa de Taquaras',
                ],
                'linha_fina' => 'Evento gratuito da Prefeitura segue neste sábado (6) e domingo (7) na Rodovia Interpraias, com shows, gastronomia típica e transmissão do amistoso do Brasil; trecho da via fica interditado até 10 de junho.',
                'materia' => $this->materia(
                    'A 7ª Festa Cultural Raízes de Taquaras segue neste sábado (6) e domingo (7) em Balneário Camboriú, na Rodovia Interpraias, próximo à Praia de Taquaras. A entrada é gratuita e a programação vai das 10h às 23h. O evento é realizado pela Prefeitura, por meio da Fundação Cultural, com apoio da Associação de Moradores.',
                    'As informações são da Secretaria Municipal de Comunicação e da Fundação Cultural de Balneário Camboriú. Segundo a diretora-presidente da Fundação Cultural, Karoen Mello, entre quinta-feira (4) e sexta-feira (5) já foram comercializadas cerca de mil tainhas e churrascos. "A Festa está muito boa e o público tem prestigiado o evento em grande número", afirmou.',
                    'De acordo com a Prefeitura, o evento reúne gastronomia típica, artesanato e programação musical, além de atividades artísticas e culturais. Nesta edição, o pavilhão coberto tem 150 metros e 43 barracas — 25% maior que em 2025 — com a maioria das tendas voltada à gastronomia, como tainha frita, frutos do mar e bolinho de camarão. O espaço conta ainda com parquinho infantil, exposição de peças de engenho e uma réplica de Casa de Pescador. A festa valoriza a identidade histórica do bairro, com destaque para a pesca artesanal e os antigos engenhos de farinha de mandioca, segundo a Fundação Cultural.',
                    'De 4 a 7 de junho, das 10h às 23h — realização da festa. Sábado (6), a partir das 19h — transmissão ao vivo do amistoso entre Brasil e Egito em telão ao lado da barraca da tainha. Até 10 de junho — interdição de trecho da Rodovia Interpraias.',
                    'A Rodovia Interpraias fica interditada entre a Rua Bougainvillea e a Alameda das Acácias até 10 de junho; o trânsito é desviado pela Alameda das Acácias (sentido Bairro–Centro) e pela Rua Bougainvillea (sentido Centro–Bairro). A programação musical de sábado (6) inclui Osmar Fernandes, Boi de Mamão, É Balanço, André Luis e Trancão de Baile; no domingo (7), Sahra Stamm, Campeiraço, Noix é da Barra e Pineapple XPS, com o DJ Carlinhos Mister Som em todos os dias. Mais informações pela Fundação Cultural, no telefone (47) 3267-7011.'
                ),
                'tags' => ['Balneário Camboriú', 'Costa Verde e Mar', 'cultura', 'Festa Raízes de Taquaras', 'Prefeitura de Balneário Camboriú', 'eventos', 'gastronomia', 'pesca artesanal'],
                'lacunas' => [
                    'Estimativa de público total não informada',
                    'Valores dos produtos não informados',
                    'Condições de acessibilidade e estacionamento não detalhadas',
                ],
            ],

            // ---------------------------------------------------------------- 5
            [
                'captura_message_id' => '2ACBF4FEC6EC677999AB',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Prefeitura de Balneário Camboriú',
                'temperatura' => 'fria',
                'confianca' => 'alta',
                'titulos' => [
                    'Prefeitura de BC lança módulo de saúde no aplicativo BC Digital',
                    'BC Digital passa a reunir serviços de saúde em módulo exclusivo',
                    'Aplicativo da Prefeitura de BC ganha área de saúde com telemedicina',
                    'Módulo Saúde do BC Digital centraliza serviços da rede municipal',
                    'BC Digital tem nova área de saúde com seção dedicada à mulher',
                    'App da Prefeitura de Balneário Camboriú concentra serviços de saúde',
                    'Moradores de BC podem acessar telemedicina e fila de espera pelo BC Digital',
                    'BC Digital: novo módulo reúne orientações e serviços de saúde em BC',
                    'Saúde da Mulher é destaque no novo módulo do app BC Digital',
                    'Balneário Camboriú amplia BC Digital com serviços de saúde',
                    'Prefeitura de BC leva serviços de saúde para o celular do cidadão',
                    'BC Digital agora oferece acompanhamento de saúde em um só lugar',
                ],
                'linha_fina' => 'Segundo a Prefeitura, o Módulo Saúde do BC Digital reúne orientações, telemedicina, consulta à fila de espera e uma seção dedicada à saúde da mulher; o app é gratuito para Android e iOS.',
                'materia' => $this->materia(
                    'A Prefeitura de Balneário Camboriú lançou o Módulo Saúde no aplicativo BC Digital, reunindo orientações, conteúdos e serviços da rede municipal de saúde em um único ambiente. A ferramenta é gratuita e está disponível para celulares Android e iOS.',
                    'As informações são da Secretaria Municipal de Comunicação e da Secretaria de Governo, Inovação e Orçamento. Segundo a Prefeitura, o módulo foi desenvolvido pela fábrica de software da Divisão de Tecnologia da Informação (DTI). "No aplicativo eles podem encontrar as informações mais rápidas, orientações, os serviços de saúde, tudo no mesmo ambiente", afirmou o secretário Gilson Bordin.',
                    'De acordo com a Prefeitura, o módulo oferece telemedicina com atendimento on-line e consulta à fila de espera. Há uma área de Saúde da Mulher, com controle do ciclo menstrual, conteúdos sobre maternidade, questionário sobre menopausa e climatério, materiais educativos e informações sobre a rede de atendimento. O módulo traz ainda informações da rede municipal, conteúdos sobre a Conferência Municipal de Saúde e uma função de Acolhimento. "É tecnologia pública funcionando a serviço do cidadão", disse o diretor da DTI, Murilo Sodré.',
                    '16 de dezembro de 2025 — lançamento do BC Digital, primeiro aplicativo oficial da Prefeitura. Atualizações periódicas — inclusão de novas funções, entre elas o Acolhimento. Agora — disponibilização do Módulo Saúde.',
                    'Segundo a Prefeitura, o BC Digital reúne serviços de saúde, transporte, turismo, zeladoria, educação, tributos, transparência, ouvidoria e desenvolvimento social. O aplicativo é gratuito e pode ser baixado em digital.bc.sc.gov.br. Mais informações pela Secretaria de Governo, Inovação e Orçamento, no telefone (47) 3267-7000.'
                ),
                'tags' => ['Balneário Camboriú', 'Costa Verde e Mar', 'saúde', 'tecnologia', 'Prefeitura de Balneário Camboriú', 'serviço público', 'BC Digital', 'saúde da mulher'],
                'lacunas' => [
                    'Número de usuários do BC Digital não informado',
                    'Data exata do lançamento do Módulo Saúde não informada',
                    'Não informado se a telemedicina já está em operação plena',
                    'Sem dados sobre custo de desenvolvimento',
                ],
            ],

            // ---------------------------------------------------------------- 6
            [
                'captura_message_id' => '2AFE80B244DEBE78D433',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Prefeitura de Balneário Camboriú (Secretaria de Saúde)',
                'temperatura' => 'fria',
                'confianca' => 'alta',
                'titulos' => [
                    'Grupos de Gestantes das UBSs orientam futuras mães em Balneário Camboriú',
                    'Saúde de BC oferece encontros de apoio a gestantes nas UBSs',
                    'Gestantes de BC tiram dúvidas sobre parto e amamentação em grupos de apoio',
                    'UBSs de Balneário Camboriú promovem encontros para gestantes',
                    'Grupos de Gestantes abordam parto, amamentação e saúde mental em BC',
                    'Balneário Camboriú mantém grupos de apoio a gestantes na rede municipal',
                    'Futuras mães de BC têm acompanhamento e orientação nas UBSs',
                    'Saúde de BC reúne gestantes para orientações sobre a gravidez',
                    'Encontros nas UBSs preparam gestantes para o parto em Balneário Camboriú',
                    'Como participar dos Grupos de Gestantes das UBSs em BC',
                    'Rede municipal de BC oferece apoio a gestantes durante a gravidez',
                    'Grupos de Gestantes ajudam mães de primeira viagem em Balneário Camboriú',
                ],
                'linha_fina' => 'Segundo a Secretaria de Saúde, encontros gratuitos nas Unidades Básicas de Saúde tratam de temas como parto, amamentação e saúde mental; cada UBS define datas e horários, e as gestantes devem procurar a unidade do bairro.',
                'materia' => $this->materia(
                    'As Unidades Básicas de Saúde (UBSs) de Balneário Camboriú promovem encontros dos Grupos de Gestantes, com orientação e apoio às futuras mães atendidas na rede municipal. As datas e horários são definidos por cada unidade.',
                    'As informações são da Secretaria de Saúde de Balneário Camboriú. Segundo a secretária Aline Leal, os encontros oferecem acolhimento e fortalecem o vínculo entre as gestantes e a rede municipal. "A gestação é um período de muitas mudanças e dúvidas, e esses encontros oferecem um espaço seguro para compartilhar experiências", afirmou.',
                    'De acordo com a Secretaria, em cada encontro um tema é trabalhado por profissionais da rede, como médicos e enfermeiras. Entre os assuntos estão alimentação e hábitos saudáveis, alterações no corpo, tipos e planos de parto, amamentação, cuidados odontológicos e saúde mental. Chayane dos Santos, de 31 anos, participante do grupo da UBS Vila Real e com 37 semanas de gestação, destacou o encontro sobre primeiros socorros com o bebê: "Muitas vezes, principalmente para as mães de primeira viagem, há muitas dúvidas sobre os cuidados necessários com os bebês."',
                    'A iniciativa é descrita pela Secretaria como um trabalho contínuo. Cada UBS define previamente as datas e os horários dos encontros e informa as gestantes atendidas na unidade.',
                    'Segundo a Secretaria de Saúde, para saber as próximas datas a gestante deve procurar a UBS do seu bairro. Mais informações pela Secretaria de Saúde, no telefone (47) 3261-6200.'
                ),
                'tags' => ['Balneário Camboriú', 'Costa Verde e Mar', 'saúde', 'gestação', 'Prefeitura de Balneário Camboriú', 'atenção básica', 'serviço público', 'saúde da mulher'],
                'lacunas' => [
                    'Calendário e locais específicos dos encontros não informados',
                    'Número de UBSs participantes não informado',
                    'Quantidade de gestantes atendidas não informada',
                ],
            ],

            // ---------------------------------------------------------------- 7
            [
                'captura_message_id' => '2A72925A241EB35E6939',
                'cidade' => 'Navegantes',
                'fonte' => 'Panorama Notícias SC',
                'temperatura' => 'fria',
                'confianca' => 'baixa',
                'titulos' => [
                    'Policiais empinam pipa com crianças durante fiscalização em Navegantes',
                    'Ação contra cerol e linha chilena tem momento com crianças em Navegantes',
                    'Em Navegantes, policiais soltam pipa com crianças durante fiscalização',
                    'Fiscalização contra cerol e linha chilena vira cena nas redes em Navegantes',
                    'Cena de policiais empinando pipa com crianças repercute em Navegantes',
                    'Navegantes: fiscalização sobre linha chilena tem momento com crianças',
                    'Policiais soltam pipa com crianças em meio a ação contra cerol em Navegantes',
                    'Ação sobre cerol e linha chilena em Navegantes repercute nas redes sociais',
                    'Momento de policiais com crianças marca fiscalização em Navegantes',
                    'Em Navegantes, fiscalização contra cerol ganha cena que viralizou',
                    'Crianças e policiais soltam pipa durante ação em Navegantes',
                    'Fiscalização de pipas com cerol em Navegantes tem registro que viralizou',
                ],
                'linha_fina' => 'Publicação do Panorama Notícias SC informa que policiais empinaram pipa com crianças durante uma ação de fiscalização contra cerol e linha chilena em Navegantes, em cena que repercutiu nas redes; conteúdo veio apenas com título e link, sem detalhes.',
                'materia' => $this->materia(
                    'Uma publicação atribuída ao Panorama Notícias SC informa que policiais empinaram pipa com crianças durante uma ação de fiscalização contra cerol e linha chilena em Navegantes. A cena, segundo a publicação, repercutiu nas redes sociais.',
                    'O material chegou à redação apenas com título e link, sem corpo de texto, fotos ou dados adicionais. As informações não foram confirmadas de forma independente.',
                    'Não há, no conteúdo recebido, detalhes sobre data, local exato, força policial envolvida ou número de pessoas. Esses pontos precisam de apuração antes de qualquer publicação.',
                    'Não informada.',
                    'É necessário apurar com a fonte e com os órgãos de segurança envolvidos antes de publicar. Vale registrar que o uso de cerol e de linha chilena é proibido por representar risco à vida; uma eventual matéria pode tratar tanto da fiscalização quanto desse alerta.'
                ),
                'tags' => ['Navegantes', 'Litoral Norte', 'comportamento', 'fiscalização', 'segurança', 'cerol e linha chilena', 'redes sociais', 'pipa'],
                'lacunas' => [
                    'Conteúdo veio só com título e link, sem corpo de texto',
                    'Data e local exato não informados',
                    'Força policial/órgão responsável não identificado',
                    'Número de envolvidos não informado',
                    'Informação não confirmada de forma independente',
                ],
            ],

            // ---------------------------------------------------------------- 8
            [
                'captura_message_id' => '2A9F0B39B1638910C618',
                'cidade' => 'São José',
                'fonte' => 'São José Agora',
                'temperatura' => 'fria',
                'confianca' => 'baixa',
                'titulos' => [
                    'Atacante Firmino é visto na trilha da Pedra Branca, em São José',
                    'Firmino aparece em São José e encara a trilha da Pedra Branca',
                    'Presença do atacante Firmino em São José movimenta as redes sociais',
                    'Atacante Firmino é flagrado na trilha da Pedra Branca, em São José',
                    'Firmino encara trilha da Pedra Branca e repercute nas redes em São José',
                    'São José: atacante Firmino encara a trilha da Pedra Branca',
                    'Aparição de Firmino na Pedra Branca viraliza em São José',
                    'Atacante Firmino surge em São José e agita torcedores nas redes',
                    'Firmino é visto fazendo a trilha da Pedra Branca, em São José',
                    'Trilha da Pedra Branca recebe o atacante Firmino, em São José',
                    'Firmino na Pedra Branca: aparição do atacante repercute em São José',
                    'Atacante Firmino movimenta redes ao aparecer em São José',
                ],
                'linha_fina' => 'Publicação do São José Agora informa que o atacante Firmino apareceu em São José e encarou a trilha da Pedra Branca, repercutindo nas redes sociais; conteúdo veio apenas com título e link, sem mais detalhes.',
                'materia' => $this->materia(
                    'Uma publicação atribuída ao portal São José Agora informa que o atacante Firmino apareceu em São José e encarou a trilha da Pedra Branca. Segundo o material, o registro repercutiu nas redes sociais.',
                    'O conteúdo chegou à redação apenas com título e link, sem corpo de texto, fotos ou confirmação. A identidade completa do atacante, a data e as circunstâncias não foram informadas.',
                    'Não há detalhes sobre quando o atacante esteve na trilha, se houve acompanhantes ou se há registro oficial. Esses pontos precisam de checagem.',
                    'Não informada.',
                    'Antes de publicar, é preciso confirmar a identidade do atacante e a veracidade do registro junto à fonte. Tema leve, de interesse local, sem urgência.'
                ),
                'tags' => ['São José', 'Grande Florianópolis', 'esporte', 'celebridades', 'trilha da Pedra Branca', 'redes sociais', 'comportamento', 'viral'],
                'lacunas' => [
                    'Conteúdo veio só com título e link, sem corpo de texto',
                    'Identidade completa do atacante não confirmada',
                    'Data e circunstâncias não informadas',
                    'Sem confirmação independente',
                ],
            ],

            // ---------------------------------------------------------------- 9
            [
                'captura_message_id' => '2AA4C1640CF47BD8F7F7',
                'cidade' => 'São José',
                'fonte' => 'São José Agora',
                'temperatura' => 'fria',
                'confianca' => 'baixa',
                'titulos' => [
                    'Mães solo de São José poderão ter vaga em creche e apoio para estudar',
                    'São José estuda garantir vaga em creche para filhos de mães solo',
                    'Programa em São José prevê creche e apoio aos estudos para mães solo',
                    'Mães solo de São José podem ganhar prioridade em creches',
                    'São José pode oferecer vaga em creche e retomada dos estudos a mães solo',
                    'Filhos de mães solo de São José poderão ter vaga em creche',
                    'Iniciativa em São José quer apoiar mães solo com creche e educação',
                    'São José: mães solo poderão contar com vaga em creche e apoio escolar',
                    'Mães solo de São José terão ajuda para concluir os estudos, diz portal',
                    'Apoio a mães solo em São José prevê creche e volta aos estudos',
                    'São José planeja vaga em creche e estudo para mães solo',
                    'Mães solo de São José podem ter creche e apoio educacional',
                ],
                'linha_fina' => 'Publicação do São José Agora informa que mães solo do município poderão ter vaga em creche para os filhos e apoio para concluir os estudos; conteúdo veio apenas com título e link, sem detalhes do programa.',
                'materia' => $this->materia(
                    'Uma publicação atribuída ao portal São José Agora informa que mães solo de São José poderão ter vaga em creche para os filhos e apoio para concluir os estudos. O conteúdo não traz detalhes sobre como a medida funcionaria.',
                    'O material chegou à redação apenas com título e link, sem corpo de texto. Não há informações sobre o responsável pela iniciativa (Prefeitura, Câmara ou outro órgão), prazos ou critérios.',
                    'Não foram informados requisitos, número de vagas, forma de inscrição ou data de início. São pontos essenciais a apurar.',
                    'Não informada.',
                    'A pauta é de interesse público e merece apuração junto à fonte e ao órgão responsável, para confirmar se é projeto, lei aprovada ou programa em execução, além de critérios e prazos.'
                ),
                'tags' => ['São José', 'Grande Florianópolis', 'cidadania', 'educação', 'mães solo', 'creche', 'políticas públicas', 'assistência social'],
                'lacunas' => [
                    'Conteúdo veio só com título e link, sem corpo de texto',
                    'Órgão responsável não identificado',
                    'Critérios, número de vagas e prazos não informados',
                    'Status da medida (projeto, lei ou programa) não confirmado',
                    'Forma de inscrição não informada',
                ],
            ],

            // --------------------------------------------------------------- 10
            [
                'captura_message_id' => '3EB0E68E4416D667B4C48E',
                'cidade' => null,
                'fonte' => 'Notícias da Região CN 20 (Carneiro News)',
                'temperatura' => 'fria',
                'confianca' => 'baixa',
                'titulos' => [
                    'Ciclones extratropicais podem ter empurrado tainhas para o litoral de SC',
                    'Tainhas no litoral catarinense podem estar ligadas a ciclones, diz portal',
                    'Por que as tainhas chegaram ao litoral de SC? Ciclones podem explicar',
                    'Ciclones são apontados como possível causa da safra de tainha em SC',
                    'Litoral catarinense recebe tainhas e ciclones podem ser a explicação',
                    'Safra de tainha em SC pode ter relação com ciclones extratropicais',
                    'Ciclones podem ter influenciado a chegada das tainhas ao litoral de SC',
                    'Tainhas no litoral de SC: ciclones extratropicais entram na explicação',
                    'Fenômeno climático pode explicar tainhas no litoral catarinense',
                    'Ciclones e a tainha: possível ligação repercute no litoral de SC',
                    'Chegada de tainhas ao litoral de SC é associada a ciclones, diz publicação',
                    'Litoral de SC: ciclones extratropicais podem ter trazido as tainhas',
                ],
                'linha_fina' => 'Publicação do Carneiro News levanta a hipótese de que ciclones extratropicais teriam empurrado as tainhas para o litoral catarinense; conteúdo veio apenas com título e link, sem dados que confirmem a relação.',
                'materia' => $this->materia(
                    'Uma publicação atribuída ao Carneiro News levanta a hipótese de que ciclones extratropicais teriam empurrado as tainhas para o litoral catarinense. Trata-se de uma possibilidade apresentada pelo portal, não de uma conclusão confirmada.',
                    'O conteúdo chegou à redação apenas com título e link, sem corpo de texto, dados ou fontes técnicas. Não há, no material, manifestação de pesquisadores, da pesca artesanal ou de órgãos ambientais.',
                    'Não foram informados dados de captura, período, locais específicos no litoral nem estudos que sustentem a relação entre os ciclones e a presença das tainhas. São informações a apurar.',
                    'Não informada.',
                    'A pauta tem apelo regional, sobretudo no período da pesca da tainha, mas depende de checagem com especialistas (meteorologia e biologia marinha) e com pescadores antes de qualquer publicação.'
                ),
                'tags' => ['Litoral catarinense', 'Santa Catarina', 'meio ambiente', 'pesca da tainha', 'clima', 'ciclone extratropical', 'economia do mar', 'região'],
                'lacunas' => [
                    'Conteúdo veio só com título e link, sem corpo de texto',
                    'Sem dados ou fontes técnicas que confirmem a hipótese',
                    'Cidade/local específico não informado',
                    'Período da observação não informado',
                    'Sem manifestação de especialistas ou pescadores',
                ],
            ],
        ];
    }
}
