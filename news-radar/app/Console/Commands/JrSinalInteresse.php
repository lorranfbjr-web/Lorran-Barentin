<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingere o "perfil de demanda" (matérias mais lidas, GA4) extraído de forma
 * read-only por /home/jr/.gam/jr_sinal_interesse_extract.py e imprime o relatório.
 *
 * Não toca no pipeline de "mais lidas" (sync_rankings.py), no n8n/dispatcher,
 * no WordPress nem na captura. Só LÊ o JSON e grava na tabela NOVA.
 */
class JrSinalInteresse extends Command
{
    protected $signature = 'jrsinal:ingest {--file= : JSON de entrada (default: storage/app/jr-sinal-interesse.json)} {--print-only}';

    protected $description = 'Ingere as matérias mais lidas (GA4) em jr_sinal_interesse e imprime o perfil de demanda do JR. Read-only no GA/pipeline; não publica nada.';

    public function handle(): int
    {
        if (! $this->option('print-only')) {
            $file = $this->option('file') ?: storage_path('app/jr-sinal-interesse.json');
            if (! is_file($file)) {
                $this->error("Arquivo não encontrado: {$file}. Rode antes o extrator GA4 (jr_sinal_interesse_extract.py).");
                return self::FAILURE;
            }
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data) || empty($data['periodos'])) {
                $this->error('JSON inválido ou vazio.');
                return self::FAILURE;
            }

            $now = Carbon::now();
            $total = 0;
            foreach ($data['periodos'] as $periodo => $bloco) {
                $rows = $bloco['rows'] ?? [];
                $chunk = [];
                foreach ($rows as $r) {
                    $chunk[] = [
                        'page_title' => (string) ($r['page_title'] ?? ''),
                        'page_path'  => (string) ($r['page_path'] ?? ''),
                        'views'      => (int) ($r['views'] ?? 0),
                        'periodo'    => (string) $periodo,
                        'created_at' => $now,
                    ];
                }
                foreach (array_chunk($chunk, 300) as $part) {
                    DB::table('jr_sinal_interesse')->upsert(
                        $part, ['page_path', 'periodo'], ['page_title', 'views', 'created_at']
                    );
                }
                $total += count($chunk);
                $this->info("periodo {$periodo}: " . count($chunk) . ' matérias');
            }
            $this->info("Total de linhas ingeridas/atualizadas: {$total}");
        }

        $this->relatorio();
        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ utils
    public function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i','î'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'];
        return strtr($s, $map);
    }

    /** Temas por palavra-chave (não exclusivo). */
    public function temasKeywords(): array
    {
        return [
            'Segurança/Crime'        => ['morte','morre','morto','corpo','assassin','crime','polic','pmsc','trafic','droga','furto','roubo','baleado','tiro','preso','prisao','golpe','estupr','homicid','cadaver','tortura','desaparec','sequestr','facada','esfaque'],
            'Política'               => ['psol','stf','tjsc','liminar','prefeit','vereador','camara','eleic','governo','deputad','senador','ministr',' lei ','cotas','votac','projeto de lei'],
            'Economia/Negócios'      => ['empresa','empresari','salario','emprego','preco','mercado','startup','growth','negocio','industri','comercio','vendedor','loja','economia','investiment','safra','produtor','pix','franquia','faturament','milionari'],
            'Trânsito/Acidente'      => ['br-','rodovia','acidente','batida','capotam','colis','transito','caminhao',' van ','atropel','engavet',' moto '],
            'Meio ambiente/Clima'    => ['ciclone','chuva','tempo','calor','frio','mare','praia','tornado','temporal','clima','vento','granizo','enxurrada','alerta','tainha','pesca'],
            'Animais/Bicho'          => ['cachorr','cao ','caes','gato','animal','bicho',' pet ','galinh','cavalo',' urso','onca','cobra','tartaruga','baleia','filhote','vaca','boi '],
            'Gente/Feel-good'        => ['emociona','sonho','surpreende','gesto','solidari','comove','ajuda',' doa','crianca','menina','menino','sindrome','down','idoso','aniversari','realiza','homenage'],
            'Saúde'                  => ['hospital','saude','medic','cancer','doenca','cirurgia','exame','vacina',' sus','uti','internad'],
            'Famosos/Celebridades'   => ['famoso','cantor','cantora','ator','atriz','influen','youtuber','novela',' bbb','embaixador','gusttavo','show'],
            'Turismo'                => ['turis','viagem','destino','hotel','pousada','feriado','temporada'],
        ];
    }

    /** Ganchos / ângulos (não exclusivo). */
    public function ganchos(string $titleNorm, string $titleRaw): array
    {
        $flags = [];
        if (preg_match('/["\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}\']/u', $titleRaw)) {
            $flags[] = 'Fala/aspas';
        }
        $sets = [
            'Indignação/revolta'  => ['farra','cagou','absurdo','revolta','indigna','vergonha','escandalo','polemica','descaso','abandon','denuncia','golpe','ilegal'],
            'Surpresa/curiosidade'=> ['surpreende','inusitad','inacredit','nao vai acreditar','olha','viraliz','viral','macabra','demoni','misterio','bizarr','estranho','inedito','chocante','impressiona','flagra'],
            'Conquista/superação' => ['realiza sonho','primeira','primeiro','conquist','emociona','supera','gesto','orgulho','premio','campeao','vitoria','recorde','heroi','homenage'],
            'Tragédia/morte'      => ['tragedia','morre','morte','vitima','fatal','luto','obito','acidente'],
        ];
        foreach ($sets as $nome => $kws) {
            foreach ($kws as $kw) {
                if (str_contains($titleNorm, $kw)) { $flags[] = $nome; break; }
            }
        }
        return $flags;
    }

    /** Cidades SC por token literal (título + path). NULL se não souber. */
    public function cidade(string $hayNorm): ?string
    {
        $patterns = [
            'Balneário Camboriú'        => ['balneario camboriu'],
            'Governador Celso Ramos'    => ['governador celso ramos','celso ramos'],
            'São Francisco do Sul'      => ['sao francisco do sul'],
            'São João Batista'          => ['sao joao batista'],
            'Balneário Piçarras'        => ['balneario picarras','picarras'],
            'Florianópolis'             => ['florianopolis','floripa','capital de santa catarina','capital catarinense'],
            'Itajaí'                    => ['itajai'],
            'São José'                  => ['sao jose'],
            'Tijucas'                   => ['tijucas'],
            'Penha'                     => ['penha'],
            'Itapema'                   => ['itapema'],
            'Navegantes'                => ['navegantes'],
            'Camboriú'                  => ['camboriu'],
            'Porto Belo'                => ['porto belo'],
            'Bombinhas'                 => ['bombinhas'],
            'Brusque'                   => ['brusque'],
            'Blumenau'                  => ['blumenau'],
            'Joinville'                 => ['joinville'],
            'Lages'                     => ['lages'],
            'Chapecó'                   => ['chapeco'],
            'Criciúma'                  => ['criciuma'],
            'Tubarão'                   => ['tubarao'],
            'Jaraguá do Sul'            => ['jaragua do sul','jaragua'],
            'Itapoá'                    => ['itapoa'],
            'Barra Velha'               => ['barra velha'],
            'Garopaba'                  => ['garopaba'],
            'Imbituba'                  => ['imbituba'],
            'Laguna'                    => ['laguna'],
            'Palhoça'                   => ['palhoca'],
            'Biguaçu'                   => ['biguacu'],
            'Gaspar'                    => ['gaspar'],
            'Pomerode'                  => ['pomerode'],
            'Canelinha'                 => ['canelinha'],
            'Nova Trento'               => ['nova trento'],
        ];
        foreach ($patterns as $cidade => $toks) {
            foreach ($toks as $t) {
                if (str_contains($hayNorm, $t)) {
                    return $cidade;
                }
            }
        }
        return null;
    }

    public function editoria(string $path): string
    {
        $seg = explode('/', trim($path, '/'))[0] ?? '';
        $map = [
            'politica'=>'Política','seguranca'=>'Segurança','justica'=>'Justiça',
            'policia'=>'Segurança','economia'=>'Economia','especiais'=>'Gente/Especiais',
            'geral'=>'Geral','meioambiente'=>'Meio ambiente','famosos'=>'Famosos',
            'esporte'=>'Esporte','esportes'=>'Esporte','saude'=>'Saúde','turismo'=>'Turismo',
            'cultura'=>'Cultura','tecnologia'=>'Tecnologia','brasil'=>'Brasil','mundo'=>'Mundo',
        ];
        return $map[$seg] ?? ($seg !== '' ? ucfirst($seg) : '(sem editoria)');
    }

    /**
     * Computa os agregados do perfil de demanda (período total) e devolve tudo,
     * para reuso pelo relatório de terminal e pelo painel HTML.
     *
     * @return array{rows:\Illuminate\Support\Collection,temaEd:array,temaKwCount:array,temaKwViews:array,cidadeCount:array,cidadeViews:array,ganchoCount:array,ganchoViews:array,leves:array,cobertura:array}
     */
    public function analise(): array
    {
        $t = 'jr_sinal_interesse';
        $rows = DB::table($t)->where('periodo', 'total')->orderByDesc('views')->get();

        $kwTemas = $this->temasKeywords();
        $temaEd = []; $temaKwCount = []; $temaKwViews = [];
        $cidadeCount = []; $cidadeViews = [];
        $ganchoCount = []; $ganchoViews = [];
        $leves = [];

        foreach ($rows as $r) {
            $titleRaw = $r->page_title;
            $tn = $this->norm($titleRaw);
            $pn = $this->norm($r->page_path);
            $hay = $tn . ' ' . $pn;
            $v = (int) $r->views;

            $ed = $this->editoria($r->page_path);
            $temaEd[$ed] = ($temaEd[$ed] ?? 0) + 1;

            $matchedTemas = [];
            foreach ($kwTemas as $nome => $kws) {
                foreach ($kws as $kw) {
                    if (str_contains($hay, $kw)) {
                        $temaKwCount[$nome] = ($temaKwCount[$nome] ?? 0) + 1;
                        $temaKwViews[$nome] = ($temaKwViews[$nome] ?? 0) + $v;
                        $matchedTemas[$nome] = true;
                        break;
                    }
                }
            }

            $cid = $this->cidade($hay);
            if ($cid) {
                $cidadeCount[$cid] = ($cidadeCount[$cid] ?? 0) + 1;
                $cidadeViews[$cid] = ($cidadeViews[$cid] ?? 0) + $v;
            }

            foreach ($this->ganchos($tn, $titleRaw) as $g) {
                $ganchoCount[$g] = ($ganchoCount[$g] ?? 0) + 1;
                $ganchoViews[$g] = ($ganchoViews[$g] ?? 0) + $v;
            }

            $hardExtra = ['faccao','execuc','executad',' pcc',' pgc','matar','mata ','matou','assassin',
                'intoxica','paralisac','greve','invas','golpe','ameaca','fuzil','fuzis','urgente',
                'preso','prisao','homicid','feminicid','estupr','abuso','crime','tortura','sequestr',
                'esfaque','facada','baleado','tiro','overdose','suicid','desaparec','corpo','cadaver'];
            $isHard = isset($matchedTemas['Segurança/Crime']) || isset($matchedTemas['Trânsito/Acidente'])
                || str_contains($tn, 'tragedia') || str_contains($tn, 'morre') || str_contains($tn, 'morte')
                || str_contains($tn, 'morto');
            if (! $isHard) {
                foreach ($hardExtra as $hx) { if (str_contains($tn, $hx)) { $isHard = true; break; } }
            }
            $isLeve = isset($matchedTemas['Gente/Feel-good']) || isset($matchedTemas['Animais/Bicho'])
                || isset($matchedTemas['Economia/Negócios']) || isset($matchedTemas['Turismo'])
                || in_array($ed, ['Gente/Especiais','Economia','Turismo'], true);
            if ($isLeve && ! $isHard) {
                $leves[] = $r;
            }
        }

        $cobertura = [];
        foreach (['2025', '2026', 'total'] as $p) {
            $cobertura[$p] = [
                'qtd'   => DB::table($t)->where('periodo', $p)->count(),
                'views' => (int) DB::table($t)->where('periodo', $p)->sum('views'),
            ];
        }

        return compact(
            'rows', 'temaEd', 'temaKwCount', 'temaKwViews',
            'cidadeCount', 'cidadeViews', 'ganchoCount', 'ganchoViews', 'leves', 'cobertura'
        );
    }

    // --------------------------------------------------------------- relatório
    private function relatorio(): void
    {
        $t = 'jr_sinal_interesse';
        $rows = DB::table($t)->where('periodo', 'total')->orderByDesc('views')->get();
        $n = $rows->count();

        $this->newLine();
        $this->line('################################################################');
        $this->line('#   PERFIL DE DEMANDA — JORNAL RAZÃO (GA4, mais lidas)          #');
        $this->line('#   Base: ' . $n . ' matérias (período total 2025-01-01 → hoje)        #');
        $this->line('################################################################');

        // contagens por ano
        $this->newLine();
        $this->line('-- Cobertura por período --');
        foreach (['2025','2026','total'] as $p) {
            $c = DB::table($t)->where('periodo',$p)->count();
            $v = DB::table($t)->where('periodo',$p)->sum('views');
            $this->line(sprintf('  %-6s: %4d matérias | %s views somadas', $p, $c, number_format((int)$v,0,',','.')));
        }

        $kwTemas = $this->temasKeywords();
        $temaEd = []; $temaKwCount = []; $temaKwViews = [];
        $cidadeCount = []; $cidadeViews = [];
        $ganchoCount = []; $ganchoViews = [];
        $leves = [];

        foreach ($rows as $r) {
            $titleRaw = $r->page_title;
            $tn = $this->norm($titleRaw);
            $pn = $this->norm($r->page_path);
            $hay = $tn . ' ' . $pn;
            $v = (int) $r->views;

            // editoria (exclusivo)
            $ed = $this->editoria($r->page_path);
            $temaEd[$ed] = ($temaEd[$ed] ?? 0) + 1;

            // temas keyword (não exclusivo)
            $matchedTemas = [];
            foreach ($kwTemas as $nome => $kws) {
                foreach ($kws as $kw) {
                    if (str_contains($hay, $kw)) {
                        $temaKwCount[$nome] = ($temaKwCount[$nome] ?? 0) + 1;
                        $temaKwViews[$nome] = ($temaKwViews[$nome] ?? 0) + $v;
                        $matchedTemas[$nome] = true;
                        break;
                    }
                }
            }

            // cidade
            $cid = $this->cidade($hay);
            if ($cid) {
                $cidadeCount[$cid] = ($cidadeCount[$cid] ?? 0) + 1;
                $cidadeViews[$cid] = ($cidadeViews[$cid] ?? 0) + $v;
            }

            // ganchos
            foreach ($this->ganchos($tn, $titleRaw) as $g) {
                $ganchoCount[$g] = ($ganchoCount[$g] ?? 0) + 1;
                $ganchoViews[$g] = ($ganchoViews[$g] ?? 0) + $v;
            }

            // pautas LEVES: feel-good / animal / economia / turismo, sem crime/tragédia
            $hardExtra = ['faccao','execuc','executad',' pcc',' pgc','matar','mata ','matou','assassin',
                'intoxica','paralisac','greve','invas','golpe','ameaca','fuzil','fuzis','urgente',
                'preso','prisao','homicid','feminicid','estupr','abuso','crime','tortura','sequestr',
                'esfaque','facada','baleado','tiro','overdose','suicid','desaparec','corpo','cadaver'];
            $isHard = isset($matchedTemas['Segurança/Crime']) || isset($matchedTemas['Trânsito/Acidente'])
                || str_contains($tn, 'tragedia') || str_contains($tn, 'morre') || str_contains($tn, 'morte')
                || str_contains($tn, 'morto');
            if (! $isHard) {
                foreach ($hardExtra as $hx) { if (str_contains($tn, $hx)) { $isHard = true; break; } }
            }
            $isLeve = isset($matchedTemas['Gente/Feel-good']) || isset($matchedTemas['Animais/Bicho'])
                || isset($matchedTemas['Economia/Negócios']) || isset($matchedTemas['Turismo'])
                || in_array($ed, ['Gente/Especiais','Economia','Turismo'], true);
            if ($isLeve && ! $isHard) {
                $leves[] = $r;
            }
        }

        $this->bloco('TOP TEMAS por editoria (path) — exclusivo', $temaEd, 12);
        $this->blocoViews('TOP TEMAS por palavra-chave (não exclusivo)', $temaKwCount, $temaKwViews, 12);
        $this->blocoViews('TOP CIDADES (token no título/path)', $cidadeCount, $cidadeViews, 15);
        $this->blocoViews('TOP GANCHOS / ÂNGULOS', $ganchoCount, $ganchoViews, 8);

        // Destaque pautas leves
        $this->newLine();
        $this->line('================================================================');
        $this->line('★ DESTAQUE — PAUTAS LEVES QUE PERFORMARAM (sem crime/tragédia)');
        $this->line('  (economia, gente/feel-good, bicho, desenvolvimento de SC)');
        $this->line('----------------------------------------------------------------');
        usort($leves, fn($a,$b) => $b->views <=> $a->views);
        foreach (array_slice($leves, 0, 25) as $i => $r) {
            $this->line(sprintf('  %2d. %8s  %s', $i+1, number_format((int)$r->views,0,',','.'),
                $this->shorten($r->page_title)));
        }
        $this->line(sprintf('  (total de matérias leves no top %d: %d)', $n, count($leves)));

        // Top 15 geral pra contexto
        $this->newLine();
        $this->line('================================================================');
        $this->line('TOP 15 GERAL (todas as pautas, período total)');
        $this->line('----------------------------------------------------------------');
        foreach ($rows->take(15) as $i => $r) {
            $this->line(sprintf('  %2d. %8s  [%s] %s', $i+1, number_format((int)$r->views,0,',','.'),
                $this->editoria($r->page_path), $this->shorten($r->page_title)));
        }
        $this->line('================================================================');
    }

    public function shorten(string $s): string
    {
        $s = preg_replace('/\s*[-–—|]\s*Jornal\s+Razão\s*$/iu', '', $s);
        return mb_strimwidth($s, 0, 78, '…', 'UTF-8');
    }

    private function bloco(string $titulo, array $count, int $limit): void
    {
        arsort($count);
        $this->newLine();
        $this->line('-- ' . $titulo . ' --');
        $i = 0;
        foreach ($count as $k => $c) {
            if ($i++ >= $limit) break;
            $this->line(sprintf('  %4d  %s', $c, $k));
        }
    }

    private function blocoViews(string $titulo, array $count, array $views, int $limit): void
    {
        arsort($views);
        $this->newLine();
        $this->line('-- ' . $titulo . ' --');
        $this->line(sprintf('  %6s  %5s  %s', 'views', 'qtd', 'categoria'));
        $i = 0;
        foreach ($views as $k => $v) {
            if ($i++ >= $limit) break;
            $this->line(sprintf('  %s  %5d  %s',
                str_pad(number_format((int)$v,0,',','.'), 6, ' ', STR_PAD_LEFT),
                $count[$k] ?? 0, $k));
        }
    }
}
