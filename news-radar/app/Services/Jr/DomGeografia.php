<?php

namespace App\Services\Jr;

/**
 * Taxonomia de geografia de SC — município → mesorregião IBGE (6 regiões:
 * Oeste, Norte, Serra, Vale do Itajaí, Grande Florianópolis, Sul).
 *
 * Mapa ESTÁTICO dos 295 municípios catarinenses (não existia taxonomia
 * município→região reutilizável no stack — as tags de região dos seeders são de
 * FONTES de notícia, não de municípios). Usado no título do card do Radar
 * DOM/SC. ISOLADO: não toca juiz/radar editorial.
 *
 * Lookup é acento-insensível e tolerante a órgão composto ("Fundo Municipal de
 * Saúde de Lages" → "Lages" já é resolvido no conector). Consórcios/entidades
 * supramunicipais (CINCATARINA etc.) caem em região nula — esperado.
 */
class DomGeografia
{
    /** Rótulos curtos das 6 mesorregiões (para o card "NOTA · MUNICÍPIO · REGIÃO"). */
    public const REGIOES = ['Oeste', 'Norte', 'Serra', 'Vale do Itajaí', 'Grande Florianópolis', 'Sul'];

    /** @var array<string,string>|null índice normalizado (nome sem acento/minúsculo → região), memoizado */
    private static ?array $indice = null;

    /**
     * Município (texto livre vindo do órgão) → rótulo da região, ou null se não
     * for um dos 295 municípios (consórcio/entidade supramunicipal).
     */
    public static function regiao(?string $municipio): ?string
    {
        if (! $municipio) {
            return null;
        }
        $idx = self::indice();
        $chave = self::norm($municipio);

        return $idx[$chave] ?? null;
    }

    /** Todos os municípios (grafia oficial), ordenados. */
    public static function municipios(): array
    {
        $out = [];
        foreach (self::MAPA as $municipios) {
            foreach ($municipios as $m) {
                $out[] = $m;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Se $nome TERMINA com um município conhecido (precedido de espaço), devolve
     * o município canônico (o mais longo que casar). Ex.: "Prefeitura Municipal
     * de São José do Cedro" → "São José do Cedro". Usado pra mapear entidade do
     * DOM → município. Devolve null se não casar (consórcio/associação regional).
     */
    public static function municipioNoFim(string $nome): ?string
    {
        $alvo = self::norm($nome);
        $melhor = null;
        $melhorLen = 0;
        foreach (self::municipios() as $m) {
            $nm = self::norm($m);
            if (mb_strlen($nm) > $melhorLen && str_ends_with($alvo, ' ' . $nm)) {
                $melhor = $m;
                $melhorLen = mb_strlen($nm);
            }
        }

        return $melhor;
    }

    private static function indice(): array
    {
        if (self::$indice !== null) {
            return self::$indice;
        }
        $idx = [];
        foreach (self::MAPA as $regiao => $municipios) {
            foreach ($municipios as $m) {
                $idx[self::norm($m)] = $regiao;
            }
        }

        return self::$indice = $idx;
    }

    /**
     * Normalização canônica de nome de município (minúsculo, sem acento, espaços
     * colapsados). Público pra CidadesInteresse/RankingExibicao usarem a MESMA
     * régua nas 4 tabelas do Radar Cívico.
     */
    public static function normalizar(string $s): string
    {
        return self::norm($s);
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $de = ['á', 'à', 'â', 'ã', 'ä', 'é', 'ê', 'è', 'í', 'ì', 'î', 'ó', 'ô', 'õ', 'ò', 'ö', 'ú', 'ù', 'û', 'ü', 'ç', 'ñ'];
        $para = ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n'];
        $s = str_replace($de, $para, $s);

        return preg_replace('/\s+/', ' ', $s);
    }

    /**
     * Mapa região → lista de municípios. Mesorregiões IBGE de Santa Catarina.
     * (Os nomes ficam com acentuação/grafia oficial; o índice normaliza no load.)
     */
    private const MAPA = [
        'Oeste' => [
            // micro São Miguel do Oeste
            'Anchieta', 'Bandeirante', 'Barra Bonita', 'Belmonte', 'Descanso', 'Dionísio Cerqueira',
            'Guaraciaba', 'Guarujá do Sul', 'Iporã do Oeste', 'Itapiranga', 'Mondaí', 'Palma Sola',
            'Paraíso', 'Princesa', 'Riqueza', 'Romelândia', 'Santa Helena', 'São João do Oeste',
            'São José do Cedro', 'São Miguel do Oeste', 'Tunápolis',
            // micro Chapecó
            'Águas de Chapecó', 'Águas Frias', 'Caibi', 'Campo Erê', 'Caxambu do Sul', 'Chapecó',
            'Cordilheira Alta', 'Coronel Freitas', 'Cunha Porã', 'Cunhataí', 'Flor do Sertão',
            'Bom Jesus do Oeste', 'Formosa do Sul', 'Guatambú', 'Iraceminha', 'Irati', 'Jardinópolis',
            'Maravilha', 'Modelo', 'Nova Erechim', 'Nova Itaberaba', 'Novo Horizonte', 'Palmitos',
            'Pinhalzinho', 'Quilombo',
            'Planalto Alegre', 'Saltinho', 'Santa Terezinha do Progresso', 'Santiago do Sul',
            'São Bernardino', 'São Carlos', 'São Lourenço do Oeste', 'São Miguel da Boa Vista',
            'Saudades', 'Serra Alta', 'Sul Brasil', 'Tigrinhos', 'União do Oeste',
            // micro Xanxerê
            'Abelardo Luz', 'Bom Jesus', 'Coronel Martins', 'Entre Rios', 'Faxinal dos Guedes',
            'Galvão', 'Ipuaçu', 'Jupiá', 'Lajeado Grande', 'Marema', 'Ouro Verde', 'Passos Maia',
            'Ponte Serrada', 'São Domingos', 'Vargeão', 'Xanxerê', 'Xaxim',
            // micro Joaçaba
            'Água Doce', 'Arroio Trinta', 'Caçador', 'Calmon', 'Capinzal', 'Catanduvas',
            'Erval Velho', 'Fraiburgo', "Herval d'Oeste", 'Ibiam', 'Ibicaré', 'Iomerê', 'Jaborá',
            'Joaçaba', 'Lacerdópolis', 'Lebon Régis', 'Luzerna', 'Macieira', 'Matos Costa', 'Ouro',
            'Pinheiro Preto', 'Rio das Antas', 'Salto Veloso', 'Tangará', 'Treze Tílias',
            'Vargem Bonita', 'Videira',
            // micro Concórdia
            'Alto Bela Vista', 'Arabutã', 'Concórdia', 'Ipira', 'Ipumirim', 'Irani', 'Itá',
            'Lindóia do Sul', 'Paial', 'Peritiba', 'Piratuba', 'Presidente Castello Branco',
            'Seara', 'Xavantina',
        ],
        'Norte' => [
            // micro Canoinhas
            'Bela Vista do Toldo', 'Canoinhas', 'Irineópolis', 'Itaiópolis', 'Mafra', 'Major Vieira',
            'Monte Castelo', 'Papanduva', 'Porto União', 'Santa Terezinha', 'Timbó Grande',
            'Três Barras',
            // micro São Bento do Sul
            'Campo Alegre', 'Rio Negrinho', 'São Bento do Sul',
            // micro Joinville
            'Araquari', 'Balneário Barra do Sul', 'Barra Velha', 'Corupá', 'Garuva', 'Guaramirim',
            'Itapoá', 'Jaraguá do Sul', 'Joinville', 'Massaranduba', 'São Francisco do Sul',
            'São João do Itaperiú', 'Schroeder',
        ],
        'Serra' => [
            // micro Curitibanos
            'Abdon Batista', 'Brunópolis', 'Campos Novos', 'Curitibanos', 'Frei Rogério',
            'Monte Carlo', 'Ponte Alta do Norte', 'Santa Cecília', 'São Cristóvão do Sul',
            'Vargem', 'Zortéa',
            // micro Campos de Lages
            'Anita Garibaldi', 'Bocaina do Sul', 'Bom Jardim da Serra', 'Bom Retiro',
            'Campo Belo do Sul', 'Capão Alto', 'Cerro Negro', 'Correia Pinto', 'Lages',
            'Otacílio Costa', 'Painel', 'Palmeira', 'Ponte Alta', 'Rio Rufino', 'São Joaquim',
            'São José do Cerrito', 'Urubici', 'Urupema',
        ],
        'Vale do Itajaí' => [
            // micro Rio do Sul
            'Agronômica', 'Braço do Trombudo', 'Dona Emma', 'Ibirama', 'José Boiteux', 'Laurentino',
            'Lontras', 'Mirim Doce', 'Pouso Redondo', 'Presidente Getúlio', 'Presidente Nereu',
            'Rio do Campo', 'Rio do Oeste', 'Rio do Sul', 'Salete',
            'Taió', 'Trombudo Central', 'Vitor Meireles', 'Witmarsum',
            // micro Ituporanga
            'Agrolândia', 'Atalanta', 'Aurora', 'Chapadão do Lageado', 'Imbuia', 'Ituporanga',
            'Petrolândia', 'Vidal Ramos',
            // micro Blumenau
            'Apiúna', 'Ascurra', 'Benedito Novo', 'Blumenau', 'Botuverá', 'Brusque',
            'Doutor Pedrinho', 'Gaspar', 'Guabiruba', 'Indaial', 'Pomerode', 'Rio dos Cedros',
            'Rodeio', 'Timbó',
            // micro Itajaí
            'Balneário Camboriú', 'Balneário Piçarras', 'Bombinhas', 'Camboriú', 'Ilhota',
            'Itajaí', 'Itapema', 'Luiz Alves', 'Navegantes', 'Penha', 'Porto Belo',
        ],
        'Grande Florianópolis' => [
            // micro Tijucas
            'Canelinha', 'Major Gercino', 'Nova Trento', 'São João Batista', 'Tijucas',
            // micro Florianópolis
            'Águas Mornas', 'Antônio Carlos', 'Biguaçu', 'Florianópolis', 'Governador Celso Ramos',
            'Palhoça', 'Santo Amaro da Imperatriz', 'São José', 'São Pedro de Alcântara',
            // micro Tabuleiro
            'Alfredo Wagner', 'Angelina', 'Anitápolis', 'Garopaba', 'Leoberto Leal', 'Paulo Lopes',
            'Rancho Queimado', 'São Bonifácio',
        ],
        'Sul' => [
            // micro Tubarão
            'Armazém', 'Braço do Norte', 'Capivari de Baixo', 'Grão-Pará', 'Gravatal', 'Imaruí',
            'Imbituba', 'Jaguaruna', 'Laguna', 'Orleans', 'Pedras Grandes', 'Pescaria Brava',
            'Rio Fortuna', 'Sangão', 'Santa Rosa de Lima', 'São Ludgero', 'São Martinho',
            'Treze de Maio', 'Tubarão',
            // micro Criciúma
            'Balneário Rincão', 'Cocal do Sul', 'Criciúma', 'Forquilhinha', 'Içara', 'Lauro Müller',
            'Morro da Fumaça', 'Nova Veneza', 'Siderópolis', 'Treviso', 'Urussanga',
            // micro Araranguá
            'Araranguá', 'Balneário Arroio do Silva', 'Balneário Gaivota', 'Ermo', 'Jacinto Machado',
            'Maracajá', 'Meleiro', 'Morro Grande', 'Passo de Torres', 'Praia Grande',
            'Santa Rosa do Sul', 'São João do Sul', 'Sombrio', 'Timbé do Sul', 'Turvo',
        ],
    ];
}
