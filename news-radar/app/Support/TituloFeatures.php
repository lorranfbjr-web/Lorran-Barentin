<?php

namespace App\Support;

/**
 * Extração de features de TÍTULO por regra (100% PHP, sem API externa).
 * Usado pelo comando jrtitulo:ingest. Determinístico e idempotente.
 */
class TituloFeatures
{
    /** Lista de cidades de SC (token literal). Ordem: mais específico primeiro. */
    public const CIDADES = [
        'Balneário Camboriú'     => ['balneario camboriu'],
        'Governador Celso Ramos' => ['governador celso ramos', 'celso ramos'],
        'São Francisco do Sul'   => ['sao francisco do sul'],
        'São João Batista'       => ['sao joao batista'],
        'Balneário Piçarras'     => ['balneario picarras', 'picarras'],
        'Florianópolis'          => ['florianopolis', 'floripa', 'capital catarinense', 'capital de santa catarina'],
        'Itajaí'                 => ['itajai'],
        'São José'               => ['sao jose'],
        'Tijucas'                => ['tijucas'],
        'Penha'                  => ['penha'],
        'Itapema'                => ['itapema'],
        'Navegantes'             => ['navegantes'],
        'Camboriú'               => ['camboriu'],
        'Porto Belo'             => ['porto belo'],
        'Bombinhas'              => ['bombinhas'],
        'Brusque'                => ['brusque'],
        'Blumenau'               => ['blumenau'],
        'Joinville'              => ['joinville'],
        'Lages'                  => ['lages'],
        'Chapecó'                => ['chapeco'],
        'Criciúma'               => ['criciuma'],
        'Tubarão'                => ['tubarao'],
        'Jaraguá do Sul'         => ['jaragua do sul', 'jaragua'],
        'Itapoá'                 => ['itapoa'],
        'Barra Velha'            => ['barra velha'],
        'Garopaba'               => ['garopaba'],
        'Imbituba'               => ['imbituba'],
        'Laguna'                 => ['laguna'],
        'Palhoça'                => ['palhoca'],
        'Biguaçu'                => ['biguacu'],
        'Gaspar'                 => ['gaspar'],
        'Pomerode'               => ['pomerode'],
        'Canelinha'              => ['canelinha'],
        'Nova Trento'            => ['nova trento'],
    ];

    private const TEMAS_KW = [
        'seguranca'         => ['morte','morre','morto','corpo','assassin','crime','polic','pmsc','trafic','droga','furto','roubo','baleado','tiro','preso','prisao','golpe','estupr','homicid','cadaver','tortura','desaparec','sequestr','facada','esfaque','bope','faccao','execuc','feminicid','operacao'],
        'transito'          => ['br-','rodovia','acidente','batida','capotam','colis','transito','caminhao',' van ','atropel','engavet',' moto ','motociclista','pedestre'],
        'politica'          => ['psol','stf','tjsc','liminar','prefeit','vereador','camara','eleic','governo','deputad','senador','ministr',' lei ','cotas','votac','projeto de lei','licitac','camara municipal'],
        'economia_negocios' => ['empresa','empresari','salario','emprego','preco','mercado','startup','growth','negocio','industri','comercio','vendedor','loja','economia','investiment','safra','produtor','pix','franquia','faturament','milionari','supermercado','shopping','obra','construc','apartament'],
        'meio_ambiente'     => ['ciclone','chuva','tempo','calor','frio','mare','praia','tornado','temporal','clima','vento','granizo','enxurrada','alerta','tainha','pesca','natureza','mar '],
        'saude'             => ['hospital','saude','medic','cancer','doenca','cirurgia','exame','vacina',' sus','uti','internad','surto','dengue'],
        'animais'           => ['cachorr','cao ','caes','gato','animal','bicho',' pet ','galinh','cavalo',' urso','onca','cobra','tartaruga','baleia','filhote','vaca','boi '],
        'feel_good_gente'   => ['emociona','sonho','surpreende','gesto','solidari','comove','ajuda',' doa','crianca','menina','menino','sindrome','down','idoso','aniversari','realiza','homenage','viral','inusitad'],
        'turismo'           => ['turis','viagem','destino','hotel','pousada','feriado','temporada','festival','festa'],
        'famosos'           => ['famoso','cantor','cantora','ator','atriz','influen','youtuber','novela',' bbb','embaixador','gusttavo','show','celebridade'],
    ];

    private const EDITORIA_TEMA = [
        'seguranca'=>'seguranca','policia'=>'seguranca','justica'=>'seguranca',
        'transito'=>'transito','economia'=>'economia_negocios','politica'=>'politica',
        'saude'=>'saude','meioambiente'=>'meio_ambiente','especiais'=>'feel_good_gente',
        'famosos'=>'famosos','turismo'=>'turismo','esporte'=>'outros','esportes'=>'outros',
    ];

    private const REGISTRO_LEVE = ['economia_negocios','animais','feel_good_gente','meio_ambiente','turismo'];
    private const REGISTRO_PESADO = ['seguranca','transito'];

    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i','î'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'];
        return strtr($s, $map);
    }

    public static function stripSuffix(string $t): string
    {
        return trim(preg_replace('/\s*[-–—|]\s*Jornal\s+Razão\s*$/iu', '', $t));
    }

    public static function extract(string $titleRaw, string $path): array
    {
        $title = self::stripSuffix($titleRaw);
        $tn = self::norm($title);
        $pn = self::norm($path);
        $hay = $tn . ' ' . $pn;

        // aspas
        $temAspas = (bool) preg_match('/["\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}\']/u', $title);
        // começa com aspas? remove emojis/símbolos/espaços iniciais e testa 1º char
        $lead = preg_replace('/^[\s\p{P}\p{S}]*?(?=["\x{201C}\x{2018}\x{00AB}\']|\p{L})/u', '', $title);
        $aspasInicio = (bool) preg_match('/^["\x{201C}\x{2018}\x{00AB}\']/u', ltrim($title)) ||
                       (bool) preg_match('/^[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\s]*["\x{201C}\x{2018}\x{00AB}\']/u', $title);

        // número
        $temNumero = (bool) preg_match('/\d/u', $title);

        // cidade + posição
        [$cidade, $cidadePos] = self::detectCidade($title, $tn, $pn);

        // cliffhanger
        $cliff = self::detectCliffhanger($tn, $title);

        // tema
        $tema = self::detectTema($path, $hay);

        // gancho
        $gancho = self::detectGancho($tn, $aspasInicio, $temAspas, $cliff);

        // comprimento
        $chars = mb_strlen($title, 'UTF-8');
        $palavras = count(preg_split('/\s+/u', trim($title), -1, PREG_SPLIT_NO_EMPTY));

        // registro
        $registro = 'neutro';
        if (in_array($tema, self::REGISTRO_PESADO, true) || $gancho === 'tragedia_morte') {
            $registro = 'pesado';
        } elseif (in_array($tema, self::REGISTRO_LEVE, true) && $gancho !== 'tragedia_morte') {
            $registro = 'leve';
        }

        return [
            'tem_aspas' => $temAspas,
            'aspas_inicio' => $aspasInicio,
            'tem_numero' => $temNumero,
            'tem_cidade' => $cidade !== null,
            'cidade' => $cidade,
            'cidade_posicao' => $cidadePos,
            'cliffhanger' => $cliff,
            'tema' => $tema,
            'gancho' => $gancho,
            'comprimento_chars' => $chars,
            'comprimento_palavras' => $palavras,
            'registro' => $registro,
        ];
    }

    private static function detectCidade(string $title, string $tn, string $pn): array
    {
        foreach (self::CIDADES as $canon => $toks) {
            foreach ($toks as $t) {
                $pos = mb_strpos($tn, $t, 0, 'UTF-8');
                if ($pos !== false) {
                    $len = max(mb_strlen($tn, 'UTF-8'), 1);
                    $frac = $pos / $len;
                    $posicao = $frac <= 0.25 ? 'inicio' : ($frac >= 0.65 ? 'fim' : 'meio');
                    return [$canon, $posicao];
                }
            }
        }
        // só no path (não no título)
        foreach (self::CIDADES as $canon => $toks) {
            foreach ($toks as $t) {
                if (str_contains($pn, $t)) {
                    return [$canon, null];
                }
            }
        }
        return [null, null];
    }

    private static function detectCliffhanger(string $tn, string $title): bool
    {
        if (str_contains($title, '…') || str_contains($title, '...')) {
            return true;
        }
        $kw = ['motivo surpreende','e o motivo','o motivo','nao vai acreditar','voce nao vai',
            'veja','veja:','assista','descubra','entenda','saiba','o que aconteceu','o que rolou',
            'o que ela','o que ele','o resultado','reviravolta','desfecho','o final','olha o que',
            'voce sabia','a reacao','o que disse','surpreende ao'];
        foreach ($kw as $k) {
            if (str_contains($tn, $k)) return true;
        }
        return false;
    }

    private static function detectTema(string $path, string $hay): string
    {
        $seg = explode('/', trim($path, '/'))[0] ?? '';
        if (isset(self::EDITORIA_TEMA[$seg]) && self::EDITORIA_TEMA[$seg] !== 'outros') {
            return self::EDITORIA_TEMA[$seg];
        }
        // editoria genérica (geral/destaques/none) -> palavra-chave, prioridade
        foreach (self::TEMAS_KW as $tema => $kws) {
            foreach ($kws as $kw) {
                if (str_contains($hay, $kw)) {
                    return $tema;
                }
            }
        }
        return 'outros';
    }

    private static function detectGancho(string $tn, bool $aspasInicio, bool $temAspas, bool $cliff): string
    {
        if ($aspasInicio) return 'fala_aspas';
        $tragedia = ['tragedia','morre','morte','morto','vitima','fatal','luto','obito'];
        foreach ($tragedia as $k) { if (str_contains($tn, $k)) return 'tragedia_morte'; }
        $indig = ['farra','cagou','absurdo','revolta','indigna','vergonha','escandalo','polemica','descaso','abandon','denuncia','ilegal','irregular'];
        foreach ($indig as $k) { if (str_contains($tn, $k)) return 'indignacao'; }
        $conq = ['realiza sonho','primeira','primeiro','conquist','emociona','supera','orgulho','premio','campeao','vitoria','recorde','heroi','homenage'];
        foreach ($conq as $k) { if (str_contains($tn, $k)) return 'conquista_superacao'; }
        if ($cliff) return 'surpresa_curiosidade';
        $surp = ['surpreende','inusitad','inacredit','viraliz','viral','macabra','demoni','misterio','bizarr','estranho','inedito','chocante','impressiona','flagra'];
        foreach ($surp as $k) { if (str_contains($tn, $k)) return 'surpresa_curiosidade'; }
        $serv = ['veja como','saiba como','confira','como ','guia','passo a passo','lista','aprenda','dicas','onde ','quando '];
        foreach ($serv as $k) { if (str_contains($tn, $k)) return 'servico'; }
        if ($temAspas) return 'fala_aspas';
        return 'outro';
    }
}
