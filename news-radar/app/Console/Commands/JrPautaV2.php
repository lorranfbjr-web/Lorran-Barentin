<?php

namespace App\Console\Commands;

use App\Support\TituloFeatures;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 2ª geração de rascunhos do JR Pauta (v5.1 prosa + título data-driven + 2 eixos).
 *
 * A reescrita NÃO usa API externa: as matérias/títulos abaixo foram redigidos
 * manualmente (pelo Claude Code, dentro do goal) a partir do texto REAL de cada
 * captura de FONTE PRIMÁRIA em jr_pauta_capturas. O vai_feed é calculado pelas
 * regras de storage/app/jr-titulo-regras.json.
 *
 * Só LÊ jr_pauta_capturas; grava na tabela NOVA jr_pauta_rascunhos_v2.
 */
class JrPautaV2 extends Command
{
    protected $signature = 'jrpautav2:gerar {--print-only}';

    protected $description = '2ª geração de rascunhos (prosa v5.1 + título data-driven + sobe_site/vai_feed). Não publica nem envia nada.';

    private array $rules = [];

    public function handle(): int
    {
        $this->rules = json_decode((string) file_get_contents(storage_path('app/jr-titulo-regras.json')), true) ?: [];

        if (! $this->option('print-only')) {
            $now = Carbon::now();
            $faltando = [];
            foreach ($this->rascunhos() as $r) {
                $cap = DB::table('jr_pauta_capturas')->where('message_id', $r['captura_message_id'])->first();
                if (! $cap) { $faltando[] = $r['captura_message_id']; continue; }

                $vaiFeed = $this->scoreVaiFeed($r['titulo_escolhido'], $r['tema'], $r['gancho'], $r['registro']);

                DB::table('jr_pauta_rascunhos_v2')->upsert([[
                    'captura_message_id' => $r['captura_message_id'],
                    'cidade' => $r['cidade'],
                    'fonte' => $r['fonte'],
                    'tema' => $r['tema'],
                    'gancho' => $r['gancho'],
                    'registro' => $r['registro'],
                    'sobe_site' => $r['sobe_site'],
                    'vai_feed' => $vaiFeed,
                    'gancho_feed' => $r['gancho_feed'],
                    'confianca' => $r['confianca'],
                    'titulo_escolhido' => $r['titulo_escolhido'],
                    'titulos' => json_encode($r['titulos'], JSON_UNESCAPED_UNICODE),
                    'linha_fina' => $r['linha_fina'],
                    'materia' => $r['materia'],
                    'lacunas' => json_encode($r['lacunas'], JSON_UNESCAPED_UNICODE),
                    'gerado_em' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['captura_message_id'], [
                    'cidade','fonte','tema','gancho','registro','sobe_site','vai_feed','gancho_feed',
                    'confianca','titulo_escolhido','titulos','linha_fina','materia','lacunas','gerado_em','updated_at',
                ]);
            }
            if ($faltando) $this->warn('Capturas não encontradas: ' . implode(', ', $faltando));
            $this->info('Rascunhos v2 gravados: ' . (count($this->rascunhos()) - count($faltando)));
        }

        // relatório texto + HTML
        $txt = $this->relatorioTexto();
        $this->line($txt);
        $htmlPath = public_path('_tmp_jrpauta_v2/v2.html');
        if (! is_dir(dirname($htmlPath))) mkdir(dirname($htmlPath), 0775, true);
        file_put_contents($htmlPath, $this->html($txt));
        $this->newLine();
        $this->info('HTML: ' . $htmlPath);

        return self::SUCCESS;
    }

    /** Score vai_feed (0-100) pelas regras do jr-titulo-regras.json. */
    private function scoreVaiFeed(string $titulo, string $tema, string $gancho, string $registro): int
    {
        $ps = $this->rules['pesos_score_vai_feed'] ?? [];
        $f = TituloFeatures::extract($titulo, '');
        $s = (int) ($ps['base'] ?? 20);
        $s += (int) ($ps['tema_pts'][$tema] ?? 0);
        $s += (int) ($ps['gancho_pts'][$gancho] ?? 0);
        $fp = $ps['feature_pts'] ?? [];
        if ($f['aspas_inicio']) $s += (int) ($fp['aspas_inicio'] ?? 0);
        if ($f['tem_cidade'])   $s += (int) ($fp['tem_cidade'] ?? 0);
        if ($f['tem_numero'])   $s += (int) ($fp['tem_numero'] ?? 0);
        if ($f['cliffhanger'])  $s += (int) ($fp['cliffhanger'] ?? 0);
        // regra condicional aspas × registro
        if ($f['aspas_inicio']) {
            $rc = $this->rules['regra_condicional_aspas'] ?? [];
            if ($registro === 'leve')   $s += (int) ($rc['tema_leve']['pts'] ?? 0);
            if ($registro === 'pesado') $s += (int) ($rc['tema_pesado']['pts'] ?? 0);
        }
        return max(0, min(100, $s));
    }

    // ===================================================================== relatório
    private function relatorioTexto(): string
    {
        $rows = DB::table('jr_pauta_rascunhos_v2')->orderByDesc('vai_feed')->get();
        $L = [];
        $L[] = '################################################################';
        $L[] = '   JR PAUTA — RASCUNHOS v2 (fonte primária · v5.1 · 2 eixos)';
        $L[] = '   ' . $rows->count() . ' rascunhos · ordenados por vai_feed · gerados do acervo real';
        $L[] = '################################################################';
        foreach ($rows as $i => $r) {
            $tit = json_decode($r->titulos, true) ?: [];
            $lac = json_decode($r->lacunas, true) ?: [];
            $L[] = '';
            $L[] = '================================================================';
            $L[] = sprintf('PAUTA %02d/%02d', $i + 1, $rows->count());
            $L[] = '----------------------------------------------------------------';
            $L[] = 'Fonte....: ' . $r->fonte;
            $L[] = 'Cidade...: ' . ($r->cidade ?? '(não informada)');
            $L[] = 'Tema.....: ' . $r->tema . '  |  Gancho: ' . $r->gancho . '  |  Registro: ' . $r->registro;
            $L[] = 'EIXOS....: sobe_site=' . ($r->sobe_site ? 'SIM' : 'não') . '   vai_feed=' . $r->vai_feed . '/100   confiança=' . strtoupper($r->confianca);
            $L[] = 'Feed.....: ' . $r->gancho_feed;
            $L[] = '';
            $L[] = '>> TÍTULO ESCOLHIDO:';
            $L[] = '   ' . $r->titulo_escolhido;
            $L[] = '';
            $L[] = 'LINHA FINA: ' . $r->linha_fina;
            $L[] = '';
            $L[] = 'MATÉRIA:';
            foreach (explode("\n", $r->materia) as $p) {
                if (trim($p) === '') { $L[] = ''; continue; }
                $L[] = '   ' . $p;
            }
            $L[] = '';
            $L[] = 'TÍTULOS ALTERNATIVOS (' . count($tit) . '):';
            foreach ($tit as $n => $t) {
                $L[] = sprintf('   %2d. %s', $n + 1, $t);
            }
            $L[] = '';
            $L[] = 'LACUNAS:';
            foreach ($lac as $l) { $L[] = '   - ' . $l; }
        }
        $L[] = '';
        $L[] = '================================================================';
        return implode("\n", $L);
    }

    private function html(string $txt): string
    {
        $e = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow, noarchive">'
            . '<title>JR Pauta — Rascunhos v2</title>'
            . '<style>body{margin:0;background:#0f1419;color:#e8e8e8;font:13px/1.55 ui-monospace,Menlo,Consolas,monospace}'
            . '.wrap{max-width:1000px;margin:0 auto;padding:14px}pre{white-space:pre-wrap;word-wrap:break-word;margin:0}'
            . 'h1{font:600 18px system-ui;margin:6px 0 12px}</style></head><body><div class="wrap">'
            . '<h1>📝 JR Pauta — Rascunhos v2 (fonte primária, v5.1)</h1><pre>' . $e . '</pre></div></body></html>';
    }

    // ===================================================================== conteúdo (escrito à mão)
    private function rascunhos(): array
    {
        return [
            // 1) Guarda Municipal recupera Mercedes — BC — conquista
            [
                'captura_message_id' => '3ABF04E6702604522DD4',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Guarda Municipal de Balneário Camboriú',
                'tema' => 'seguranca', 'gancho' => 'conquista_superacao', 'registro' => 'pesado',
                'sobe_site' => true, 'confianca' => 'alta',
                'gancho_feed' => 'Final positivo e ação rápida (menos de 3h) com número concreto: conquista que rende compartilhamento no feed.',
                'titulo_escolhido' => 'Guarda Municipal recupera em Balneário Camboriú carro furtado em menos de três horas',
                'titulos' => [
                    'Guarda Municipal recupera em Balneário Camboriú carro furtado em menos de três horas',
                    'Em menos de três horas, Guarda Municipal acha em Balneário Camboriú Mercedes furtado',
                    'Mercedes furtado é recuperado em Balneário Camboriú e dois jovens de 18 anos são presos',
                    'Ação rápida da Guarda Municipal recupera em Balneário Camboriú carro levado havia três horas',
                    'ROMU localiza no Bairro das Nações, em Balneário Camboriú, Mercedes furtado com dois ocupantes',
                    'Central 153 aciona a ROMU e recupera em Balneário Camboriú um Mercedes furtado na mesma noite',
                    'Carro furtado no Ariribá é recuperado em Balneário Camboriú e devolvido ao dono na madrugada',
                    'Dois homens de 18 anos são presos em Balneário Camboriú após furto de carro de luxo',
                    'Guarda Municipal de Balneário Camboriú prende dupla e recupera Mercedes em três horas',
                    'Furto de Mercedes termina com prisão e carro devolvido ao dono em Balneário Camboriú',
                    'Em Balneário Camboriú, Guarda recupera Mercedes furtado e prende dois em menos de 3 horas',
                    'Mercedes CLA furtado é achado com dois ocupantes na Rua Indonésia, em Balneário Camboriú',
                ],
                'linha_fina' => 'Central de Operações 153 acionou a ROMU após o furto no Bairro Ariribá; dois homens de 18 anos foram presos e o Mercedes-Benz devolvido ao dono ainda na madrugada, segundo a Guarda Municipal.',
                'materia' => "A Guarda Municipal de Balneário Camboriú recuperou um carro furtado em menos de três horas na noite de sexta-feira (5), por volta das 23h40, no Bairro das Nações. Dois homens de 18 anos foram presos e o veículo, um Mercedes-Benz CLA 200 azul, foi devolvido ao proprietário.\n\nSegundo a Guarda Municipal, a Central de Operações 153 recebeu o alerta do furto, ocorrido menos de três horas antes no Bairro Ariribá, e repassou as informações às equipes. Uma guarnição da Ronda Ostensiva Municipal localizou o automóvel na Rua Indonésia, com dois ocupantes.\n\nOs dois homens foram abordados, presos e encaminhados à Central de Plantão Policial. O carro foi entregue ao dono ainda durante a madrugada.\n\nA corporação não informou os nomes dos presos nem como o furto ocorreu. A Polícia Civil deve dar continuidade à apuração.",
                'lacunas' => ['Nomes e antecedentes dos presos não informados','Como o furto ocorreu (se houve violência) não informado','Placa do veículo não detalhada','Se houve perseguição não informado'],
            ],

            // 2) BPM Itapema tráfico — Itapema — segurança rotineira
            [
                'captura_message_id' => '3A2A61B83BF8AF892AB8',
                'cidade' => 'Itapema',
                'fonte' => 'Polícia Militar (31º BPM)',
                'tema' => 'seguranca', 'gancho' => 'outro', 'registro' => 'pesado',
                'sobe_site' => true, 'confianca' => 'alta',
                'gancho_feed' => 'Apreensão local com número concreto (22 porções), mas segurança rotineira: alcance moderado no feed.',
                'titulo_escolhido' => 'Suspeito de tráfico é preso em Itapema com 22 porções de cocaína prontas para venda',
                'titulos' => [
                    'Suspeito de tráfico é preso em Itapema com 22 porções de cocaína prontas para venda',
                    'Polícia Militar prende em Itapema homem com 21 gramas de cocaína fracionada',
                    'Patrulha da PM encontra em Itapema cocaína fracionada e R$ 871 com suspeito',
                    'No Alto São Bento, em Itapema, PM apreende cocaína e prende suspeito de tráfico',
                    'PM apreende em Itapema 22 porções de cocaína e prende um no bairro Alto São Bento',
                    'Odor de droga leva a PM a flagrante de tráfico em Itapema com 22 porções de cocaína',
                    'Homem é preso por tráfico em Itapema com cocaína fracionada e dinheiro trocado',
                    'Flagrante da PM em Itapema termina com apreensão de cocaína e R$ 871 em dinheiro',
                    'Polícia Militar tira de circulação 22 porções de cocaína em Itapema e prende um',
                    'Suspeito é encaminhado à Polícia Civil após apreensão de cocaína em Itapema',
                    'Em Itapema, PM prende homem com 21 gramas de cocaína em 22 porções',
                    'Tráfico em via pública leva à prisão de suspeito no Alto São Bento, em Itapema',
                ],
                'linha_fina' => 'Segundo o 31º BPM, guarnição em patrulhamento encontrou a droga já fracionada e R$ 871 em dinheiro; o suspeito foi encaminhado à Polícia Civil. Nome e idade não foram informados.',
                'materia' => "A Polícia Militar prendeu um homem por tráfico de drogas na noite de sexta-feira (5), no bairro Alto São Bento, em Itapema. Com ele foram apreendidas cerca de 21 gramas de cocaína divididas em 22 porções, além de R\$ 871 em dinheiro.\n\nSegundo o 31º Batalhão da Polícia Militar, a guarnição fazia patrulhamento quando viu um homem consumindo entorpecente em via pública, em frente a um conjunto de kitnets. Durante o atendimento, os policiais sentiram forte odor vindo de uma das residências e, ao verificar, encontraram a droga já fracionada em embalagens plásticas, além de embalagens vazias e o dinheiro em moedas e cédulas de pequeno valor.\n\nDiante dos indícios de tráfico, segundo a corporação, o suspeito recebeu voz de prisão e foi encaminhado à Polícia Civil, junto com o material apreendido.\n\nA Polícia Militar não informou o nome nem a idade do suspeito. O caso será apurado pela Polícia Civil.",
                'lacunas' => ['Nome e idade do suspeito não informados','Horário exato não informado','Antecedentes não informados','Quantidade de maconha citada não detalhada'],
            ],

            // 3) BOPE confronto — Florianópolis — tragédia/morte
            [
                'captura_message_id' => '3AA574A50324AFB8137E',
                'cidade' => 'Florianópolis',
                'fonte' => 'Polícia Militar (BOPE/PMSC)',
                'tema' => 'seguranca', 'gancho' => 'tragedia_morte', 'registro' => 'pesado',
                'sobe_site' => true, 'confianca' => 'media',
                'gancho_feed' => 'Fato grave em local de alto interesse; tragédia/morte puxa clique, com atenção menor segundo o histórico.',
                'titulo_escolhido' => 'Homem morre e outro é preso após confronto com o BOPE em Florianópolis, diz PM',
                'titulos' => [
                    'Homem morre e outro é preso após confronto com o BOPE em Florianópolis, diz PM',
                    'Confronto com o BOPE deixa um morto e um preso na Costeira, em Florianópolis',
                    'Em Florianópolis, ação do BOPE na Costeira termina com um morto e um detido',
                    'BOPE apreende pistola e drogas em Florianópolis após confronto na Cidade Alta',
                    'Operação do BOPE na Costeira, em Florianópolis, deixa um morto e arma apreendida',
                    'Tiroteio com o BOPE na comunidade da Cidade Alta, em Florianópolis, deixa um morto',
                    'Após ser recebida a tiros, guarnição do BOPE reage na Costeira, em Florianópolis',
                    'Confronto na Costeira, em Florianópolis, termina com prisão e apreensão de pistola',
                    'Um morto e um preso em ação do BOPE no bairro Costeira, em Florianópolis',
                    'BOPE prende suspeito e apreende drogas após confronto em Florianópolis',
                    'Em Florianópolis, confronto com o BOPE na Cidade Alta deixa um morto, diz a PM',
                    'Ação do BOPE na Costeira, em Florianópolis, tem um morto e uma pistola apreendida',
                ],
                'linha_fina' => 'Segundo a Polícia Militar, a guarnição foi recebida a tiros na comunidade da Cidade Alta; um suspeito morreu e outro foi preso, com apreensão de pistola, munições e drogas. O caso será periciado pela Polícia Científica.',
                'materia' => "Um homem morreu e outro foi preso na noite de sexta-feira (5), por volta das 23h, durante um confronto com o Batalhão de Operações Policiais Especiais na comunidade da Cidade Alta, no bairro Costeira, em Florianópolis. Segundo a Polícia Militar, a guarnição foi recebida a tiros ao tentar abordar dois suspeitos.\n\nConforme o relato da corporação, os dois homens fugiram e um deles atirou contra os policiais, que reagiram. Um dos suspeitos foi atingido e não resistiu aos ferimentos, e o outro foi preso. A versão é da Polícia Militar e ainda será apurada.\n\nSegundo a PM, foram apreendidos uma pistola, munições, drogas e uma balança de precisão. A corporação informou que o suspeito que teria atirado tinha registros por outros crimes.\n\nMortes em ação policial são investigadas pela Polícia Civil, com perícia da Polícia Científica. Até a publicação, não havia manifestação de familiares ou da defesa. Os envolvidos não foram identificados.",
                'lacunas' => ['Identidade e idade dos envolvidos não informadas','Sem manifestação de familiares ou da defesa (contraditório)','Resultado da perícia da Polícia Científica não disponível','Quantidades exatas das drogas não detalhadas','Versão é unilateral da PM'],
            ],

            // 4) Acidente fatal moto — Porto Belo — trânsito/tragédia
            [
                'captura_message_id' => '3A5EAB91AF1152B9EBFC',
                'cidade' => 'Porto Belo',
                'fonte' => 'Polícia Militar',
                'tema' => 'transito', 'gancho' => 'tragedia_morte', 'registro' => 'pesado',
                'sobe_site' => true, 'confianca' => 'media',
                'gancho_feed' => 'Tragédia local em avenida movimentada; interesse alto na cidade, registro pesado de menor retenção.',
                'titulo_escolhido' => 'Motociclista morre após acidente na Avenida Governador Celso Ramos, em Porto Belo',
                'titulos' => [
                    'Motociclista morre após acidente na Avenida Governador Celso Ramos, em Porto Belo',
                    'Em Porto Belo, motociclista não resiste a acidente no Centro e morre, diz a PM',
                    'Acidente de trânsito deixa um motociclista morto no Centro de Porto Belo',
                    'Homem morre após acidente de moto na Governador Celso Ramos, em Porto Belo',
                    'Motociclista é encontrado desacordado e morre após acidente em Porto Belo',
                    'Polícia Científica faz perícia após morte de motociclista em Porto Belo',
                    'Acidente no Centro de Porto Belo termina com a morte de um motociclista',
                    'Samu constata morte por traumatismo após acidente de moto em Porto Belo',
                    'Vítima de acidente de moto morre no Centro de Porto Belo, segundo a Polícia Militar',
                    'Motociclista morre em acidente na principal avenida do Centro de Porto Belo',
                    'Em Porto Belo, motociclista morre após ser achado caído na Governador Celso Ramos',
                    'Acidente de trânsito em Porto Belo deixa um morto e mobiliza Bombeiros e Samu',
                ],
                'linha_fina' => 'Segundo a Polícia Militar, transeuntes acharam o homem desacordado na via e Bombeiros e Samu tentaram reanimá-lo sem êxito. A Polícia Científica fará a perícia; a idade da vítima não foi informada.',
                'materia' => "Um motociclista morreu na noite de sexta-feira (5) após um acidente de trânsito na Avenida Governador Celso Ramos, no Centro de Porto Belo. A vítima foi identificada pela guarnição como Vinícius Silva Araújo.\n\nSegundo a Polícia Militar, transeuntes encontraram o homem desacordado na via e acionaram o socorro. Equipes do Corpo de Bombeiros e do Samu tentaram reanimá-lo, mas ele não resistiu. O Samu constatou morte por traumatismo craniano.\n\nA Polícia Civil foi acionada e esteve no local, assim como a Polícia Científica, responsável pela perícia. A motocicleta ficou sob responsabilidade da Polícia Civil para os exames.\n\nAs circunstâncias do acidente ainda serão apuradas. A idade da vítima não foi informada.",
                'lacunas' => ['Idade da vítima não informada','Causa do acidente não esclarecida (colisão ou queda)','Se havia outros envolvidos não informado','Confirmação oficial da identidade pendente'],
            ],

            // 5) Festa Raízes de Taquaras — BC — cultura/turismo leve
            [
                'captura_message_id' => '3A17872E6A8710113FF2',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Prefeitura de Balneário Camboriú (Fundação Cultural)',
                'tema' => 'turismo', 'gancho' => 'servico', 'registro' => 'leve',
                'sobe_site' => true, 'confianca' => 'alta',
                'gancho_feed' => 'Pauta leve de cultura/serviço com número e cidade, sem aspas (registro leve): bom alcance no feed e útil ao leitor.',
                'titulo_escolhido' => 'Festa Raízes de Taquaras leva shows e mil tainhas a Balneário Camboriú no fim de semana',
                'titulos' => [
                    'Festa Raízes de Taquaras leva shows e mil tainhas a Balneário Camboriú no fim de semana',
                    'Com entrada gratuita, Festa de Taquaras segue até domingo em Balneário Camboriú',
                    'Festa Raízes de Taquaras tem telão do Brasil e 43 barracas em Balneário Camboriú',
                    'Em Balneário Camboriú, Festa de Taquaras reúne gastronomia típica e shows até domingo',
                    'Festa de Taquaras já vendeu cerca de mil tainhas e segue no fim de semana em BC',
                    'Rodovia Interpraias fica interditada até 10 de junho pela Festa de Taquaras, em BC',
                    'Festa Raízes de Taquaras tem programação gratuita neste sábado e domingo em BC',
                    'Telão do amistoso do Brasil é atração da Festa de Taquaras, em Balneário Camboriú',
                    'Gastronomia, artesanato e shows marcam a Festa de Taquaras, em Balneário Camboriú',
                    'Festa de Taquaras celebra a pesca artesanal e segue até domingo em Balneário Camboriú',
                    'Em Balneário Camboriú, 7ª Festa de Taquaras tem 43 barracas e entrada gratuita',
                    'Festa Raízes de Taquaras movimenta a Interpraias em Balneário Camboriú no fim de semana',
                ],
                'linha_fina' => 'Evento gratuito da Prefeitura segue no sábado (6) e domingo (7) na Rodovia Interpraias, com shows, gastronomia e telão do amistoso do Brasil; trecho da via fica interditado até 10 de junho.',
                'materia' => "A 7ª Festa Cultural Raízes de Taquaras segue neste fim de semana em Balneário Camboriú, com entrada gratuita, na Rodovia Interpraias, próximo à Praia de Taquaras. A programação vai das 10h às 23h no sábado (6) e no domingo (7), com shows, gastronomia típica e artesanato.\n\nO evento é realizado pela Prefeitura, por meio da Fundação Cultural, com apoio da Associação de Moradores. Segundo a Fundação Cultural, entre quinta (4) e sexta (5) já foram vendidas cerca de mil tainhas e churrascos. A festa valoriza a pesca artesanal e os antigos engenhos de farinha de mandioca do bairro.\n\nNeste sábado (6), a partir das 19h, a organização instala um telão para a transmissão do amistoso entre Brasil e Egito, ao lado da barraca da tainha. O pavilhão coberto tem 150 metros e 43 barracas, a maioria de gastronomia.\n\nPor causa da festa, a Rodovia Interpraias fica interditada entre a Rua Bougainvillea e a Alameda das Acácias até 10 de junho, com desvios sinalizados. Mais informações pela Fundação Cultural, no telefone (47) 3267-7011.",
                'lacunas' => ['Estimativa de público total não informada','Valores dos pratos não informados','Acessibilidade e estacionamento não detalhados'],
            ],

            // 6) BC Digital Saúde — BC — saúde/serviço
            [
                'captura_message_id' => '2ACBF4FEC6EC677999AB',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Prefeitura de Balneário Camboriú',
                'tema' => 'saude', 'gancho' => 'servico', 'registro' => 'neutro',
                'sobe_site' => true, 'confianca' => 'alta',
                'gancho_feed' => 'Serviço útil ao morador com cidade no título; alcance moderado, registro neutro sem aspas.',
                'titulo_escolhido' => 'Aplicativo BC Digital ganha módulo de saúde com telemedicina em Balneário Camboriú',
                'titulos' => [
                    'Aplicativo BC Digital ganha módulo de saúde com telemedicina em Balneário Camboriú',
                    'Em Balneário Camboriú, BC Digital passa a reunir serviços de saúde no celular',
                    'Moradores de Balneário Camboriú já têm telemedicina pelo aplicativo BC Digital',
                    'BC Digital concentra fila de espera e telemedicina em Balneário Camboriú',
                    'Saúde da Mulher é destaque no novo módulo do BC Digital, em Balneário Camboriú',
                    'Prefeitura de Balneário Camboriú leva serviços de saúde para o aplicativo BC Digital',
                    'App BC Digital reúne orientações e serviços de saúde em Balneário Camboriú',
                    'Em Balneário Camboriú, telemedicina e fila de espera ficam a um toque no BC Digital',
                    'Módulo de saúde do BC Digital chega gratuito a moradores de Balneário Camboriú',
                    'Balneário Camboriú amplia o BC Digital com agenda e serviços de saúde',
                    'BC Digital tem nova área de saúde e telemedicina em Balneário Camboriú',
                    'Como usar o módulo de saúde do BC Digital, em Balneário Camboriú',
                ],
                'linha_fina' => 'Segundo a Prefeitura, o módulo gratuito reúne telemedicina, consulta à fila de espera e uma seção de saúde da mulher; o app está disponível para Android e iOS.',
                'materia' => "A Prefeitura de Balneário Camboriú lançou o módulo de Saúde no aplicativo BC Digital, que reúne orientações e serviços da rede municipal em um único ambiente. O aplicativo é gratuito e está disponível para celulares Android e iOS.\n\nSegundo a Prefeitura, o módulo oferece telemedicina com atendimento on-line e consulta à fila de espera. Há uma área de Saúde da Mulher, com acompanhamento do ciclo menstrual, conteúdos sobre maternidade e um questionário sobre menopausa, além de informações sobre a rede de atendimento.\n\nA ferramenta foi desenvolvida pela equipe de tecnologia da própria Prefeitura. O BC Digital, primeiro aplicativo oficial do município, foi lançado em dezembro de 2025 e reúne serviços de saúde, transporte, educação, tributos e outros.\n\nO download pode ser feito pelo endereço digital.bc.sc.gov.br. Mais informações pela Secretaria de Governo, Inovação e Orçamento, no telefone (47) 3267-7000.",
                'lacunas' => ['Número de usuários do app não informado','Se a telemedicina já opera em capacidade plena não informado','Data exata do lançamento do módulo não informada'],
            ],

            // 7) EMASA falta de água — BC — serviço/utilidade
            [
                'captura_message_id' => 'ACD4B877F92D061BCA67B30A3C6919D3',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Emasa (Empresa Municipal de Água e Saneamento)',
                'tema' => 'outros', 'gancho' => 'servico', 'registro' => 'neutro',
                'sobe_site' => true, 'confianca' => 'alta',
                'gancho_feed' => 'Utilidade pública imediata (falta de água) com cidade e horários: alto valor de serviço, compartilhável no feed local.',
                'titulo_escolhido' => 'Reparo da Emasa pode deixar parte do Centro de Balneário Camboriú sem água neste sábado',
                'titulos' => [
                    'Reparo da Emasa pode deixar parte do Centro de Balneário Camboriú sem água neste sábado',
                    'Em Balneário Camboriú, Emasa fecha rede às 14h e abastecimento volta até as 19h',
                    'Bairro Centro de Balneário Camboriú pode ficar sem água durante reparo neste sábado',
                    'Emasa faz reparo emergencial e parte de Balneário Camboriú pode ficar sem água',
                    'Falta de água atinge região central de Balneário Camboriú durante conserto neste sábado',
                    'Vazamento na Rua 3000 leva a Emasa a fechar rede no Centro de Balneário Camboriú',
                    'Abastecimento de água é interrompido em parte de Balneário Camboriú para reparo',
                    'Em Balneário Camboriú, Emasa pede economia de água durante reparo neste sábado',
                    'Conserto emergencial da Emasa afeta o abastecimento no Centro de Balneário Camboriú',
                    'Parte do Centro de Balneário Camboriú fica sem água das 14h às 19h neste sábado',
                    'Emasa avisa moradores de Balneário Camboriú sobre corte de água para reparo',
                    'Reparo em vazamento interrompe a água em ruas do Centro de Balneário Camboriú',
                ],
                'linha_fina' => 'Segundo a Emasa, a rede será fechada às 14h para conserto de um vazamento na Rua 3000; o abastecimento deve normalizar de forma gradual até as 19h. A empresa pede economia de água.',
                'materia' => "A Empresa Municipal de Água e Saneamento de Balneário Camboriú informou que faz um reparo emergencial em um vazamento na rede de abastecimento na Rua 3000, no Centro, neste sábado (6). Para o serviço, a rede será fechada a partir das 14h.\n\nSegundo a Emasa, pode haver interrupção temporária no fornecimento de água na região entre a Rua 3610 e a Rua 1500 e entre a Terceira Avenida e a Avenida Brasil. A previsão é de que o abastecimento volte ao normal de forma gradual até as 19h.\n\nA empresa recomenda que os moradores economizem água durante a intervenção e afirma que equipes ficam no local até concluir o reparo. Outras informações podem ser obtidas pelo WhatsApp da Emasa, no número (47) 3261-0000.",
                'lacunas' => ['Número de imóveis afetados não informado','Causa do vazamento não informada'],
            ],

            // 8) PRF Pare e Siga — Guaraciaba — trânsito/serviço (fora da região)
            [
                'captura_message_id' => '3EB0A34709FC9CE9F9EA23',
                'cidade' => 'Guaraciaba',
                'fonte' => 'Polícia Rodoviária Federal (PRF)',
                'tema' => 'transito', 'gancho' => 'servico', 'registro' => 'pesado',
                'sobe_site' => true, 'confianca' => 'alta',
                'gancho_feed' => 'Serviço de trânsito, mas fora da região de cobertura do JR (Oeste de SC): utilidade baixa para o público do feed.',
                'titulo_escolhido' => 'BR-163 segue com Pare e Siga 24 horas em Guaraciaba por causa de obras',
                'titulos' => [
                    'BR-163 segue com Pare e Siga 24 horas em Guaraciaba por causa de obras',
                    'Em Guaraciaba, obras mantêm o sistema Pare e Siga 24 horas na BR-163',
                    'Motoristas enfrentam Pare e Siga na BR-163, em Guaraciaba, no Oeste de SC',
                    'Obras na BR-163 deixam o trânsito alternado 24 horas em Guaraciaba',
                    'PRF alerta para filas no km 85 da BR-163, em Guaraciaba, durante obras',
                    'Trânsito na BR-163 opera em meia pista 24 horas em Guaraciaba, diz a PRF',
                    'Restauração de pista mantém o Pare e Siga na BR-163, em Guaraciaba',
                    'Em Guaraciaba, PRF pede atenção redobrada na BR-163 por causa de obras',
                    'BR-163 tem lentidão e Pare e Siga em trecho urbano de Guaraciaba',
                    'PRF orienta motoristas a prever filas na BR-163, em Guaraciaba',
                    'Obras na BR-163 exigem tempo extra de viagem em Guaraciaba, alerta a PRF',
                    'Pare e Siga 24 horas segue na BR-163 no Oeste de SC, em Guaraciaba',
                ],
                'linha_fina' => 'Segundo a PRF, o km 85 da BR-163, em trecho urbano de Guaraciaba, opera em meia pista nas 24 horas por causa de obras de restauração; motoristas devem prever filas.',
                'materia' => "A Polícia Rodoviária Federal informou que a BR-163, em Guaraciaba, no Oeste de Santa Catarina, segue com o sistema Pare e Siga nas 24 horas do dia, no km 85, em trecho urbano. A medida é por causa de obras de restauração da pista.\n\nSegundo a PRF, o trânsito opera de forma alternada e os motoristas devem prever lentidão e filas nos dois sentidos. A corporação recomenda redução de velocidade e atenção redobrada por causa do fluxo de trabalhadores e de máquinas pesadas na área.\n\nA orientação é planejar a viagem com tempo extra de deslocamento. A PRF informa que divulga atualizações pelo seu canal oficial.",
                'lacunas' => ['Prazo de conclusão das obras não informado','Extensão exata do trecho não informada','Empresa responsável pela obra não informada'],
            ],

            // 9) Vaquinha Gael — solidariedade — feel-good (sensível)
            [
                'captura_message_id' => '3EB01E97B5A3A8BAF61FE2',
                'cidade' => null,
                'fonte' => 'Relato de leitor enviado ao Jornal',
                'tema' => 'feel_good_gente', 'gancho' => 'conquista_superacao', 'registro' => 'leve',
                'sobe_site' => true, 'confianca' => 'baixa',
                'gancho_feed' => 'Solidariedade de forte apelo emocional (feel-good), alto potencial de compartilhamento, mas exige verificação antes de publicar.',
                'titulo_escolhido' => 'Família cria vaquinha para custear tratamento de criança que usa sonda em SC',
                'titulos' => [
                    'Família cria vaquinha para custear tratamento de criança que usa sonda em SC',
                    'Pais buscam ajuda para tratamento de menino que não pode ir à escola em SC',
                    'Vaquinha tenta cobrir despesas médicas de criança com sonda de alimentação em SC',
                    'Família pede solidariedade para bancar o tratamento de filho com sonda em SC',
                    'Campanha online quer ajudar criança que depende de sonda de alimentação em SC',
                    'Mãe deixa o trabalho para cuidar de filho com sonda e família cria vaquinha em SC',
                    'Família recorre a vaquinha para custear deslocamento e remédios de criança em SC',
                    'Tratamento de menino com sonda mobiliza vaquinha de solidariedade em SC',
                    'Pais de criança com sonda de alimentação pedem ajuda para manter o tratamento',
                    'Vaquinha busca apoio para criança que não pode frequentar a escola em SC',
                    'Família pede ajuda da comunidade para cuidar de filho com sonda em SC',
                    'Solidariedade: campanha tenta custear o tratamento de criança com sonda em SC',
                ],
                'linha_fina' => 'Segundo o relato enviado ao Jornal, a mãe precisa acompanhar o filho em tempo integral e a renda não cobre as despesas; a reportagem vai verificar a campanha antes de divulgar os canais de doação.',
                'materia' => "Uma família que pediu ajuda ao Jornal Razão criou uma vaquinha on-line para custear despesas médicas e de deslocamento de uma criança que usa sonda de alimentação. Segundo o relato enviado, o menino não pode frequentar a escola junto com o irmão gêmeo por causa do tratamento.\n\nDe acordo com a família, a mãe precisa acompanhar o filho em período integral e não pode trabalhar, e a renda atual não cobre as despesas da casa. O relato afirma que o aluguel consome quase todo o salário e que tentativas de apoio na assistência social não tiveram resultado.\n\nA reportagem ainda vai confirmar os dados da campanha e os canais oficiais de doação antes de divulgá-los. A cidade da família não foi informada.",
                'lacunas' => ['Cidade da família não informada','Autenticidade da vaquinha e da chave Pix a confirmar','Quadro de saúde e laudo médico não verificados','Nome completo da criança preservado por ser menor de idade'],
            ],

            // 10) Oficina de Xadrez para idosos — BC — feel-good/serviço
            [
                'captura_message_id' => '2AA79DD3BCF2A861BF35',
                'cidade' => 'Balneário Camboriú',
                'fonte' => 'Prefeitura de Balneário Camboriú (Secretaria da Pessoa Idosa)',
                'tema' => 'feel_good_gente', 'gancho' => 'servico', 'registro' => 'leve',
                'sobe_site' => true, 'confianca' => 'alta',
                'gancho_feed' => 'Pauta leve e positiva (envelhecimento ativo) com serviço e cidade, sem aspas em pauta leve: bom feed de comunidade.',
                'titulo_escolhido' => 'Balneário Camboriú abre inscrições para oficina gratuita de xadrez para idosos',
                'titulos' => [
                    'Balneário Camboriú abre inscrições para oficina gratuita de xadrez para idosos',
                    'Oficina de xadrez para idosos tem vagas abertas em Balneário Camboriú',
                    'Em Balneário Camboriú, idosos têm oficina gratuita de xadrez duas vezes por semana',
                    'Secretaria da Pessoa Idosa oferece xadrez de graça a idosos em Balneário Camboriú',
                    'Xadrez para a memória: idosos de Balneário Camboriú têm oficina gratuita',
                    'Oficina gratuita de xadrez estimula a memória de idosos em Balneário Camboriú',
                    'Idosos de Balneário Camboriú podem aprender xadrez de graça às segundas e quartas',
                    'Balneário Camboriú leva xadrez a idosos para estimular memória e socialização',
                    'Inscrições abertas para oficina de xadrez de idosos em Balneário Camboriú',
                    'Projeto de xadrez para idosos tem aulas gratuitas no Centro de Balneário Camboriú',
                    'Em Balneário Camboriú, oficina de xadrez ajuda no envelhecimento ativo dos idosos',
                    'Como participar da oficina gratuita de xadrez para idosos em Balneário Camboriú',
                ],
                'linha_fina' => 'Aulas gratuitas acontecem às segundas e quartas, das 15h às 17h, na sede da Secretaria da Pessoa Idosa, no Centro; as inscrições estão abertas durante a semana.',
                'materia' => "A Secretaria da Pessoa Idosa de Balneário Camboriú oferece, de graça, uma oficina de xadrez para os idosos do município. As aulas acontecem às segundas e quartas-feiras, das 15h às 17h, na sede da secretaria, na Rua 1822, no Centro, e as inscrições estão abertas.\n\nSegundo o secretário da Pessoa Idosa, Claudir Maciel, o xadrez estimula a memória e a socialização em qualquer idade e ajuda a construir um envelhecimento mais independente. As inscrições podem ser feitas na própria secretaria, de segunda a sexta-feira, das 8h às 18h.\n\nA atividade é conduzida pelo professor voluntário José Humberto Caimi, que destaca o ganho de concentração e de saúde mental com a prática. Mais informações pela Secretaria da Pessoa Idosa, no telefone (47) 3267-7054.",
                'lacunas' => ['Número de vagas não informado','Data de início da turma não informada'],
            ],
        ];
    }
}
