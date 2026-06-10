<?php

namespace Database\Seeders;

use App\Modules\NewsRadar\Models\NewsSource;
use Illuminate\Database\Seeder;

/**
 * Pós-Fase 2 (2026-06-10): fontes aprovadas no QA da lista crua
 * /home/jr/dominios-sc-raw.txt (144 domínios → probe HTTP real + notícia
 * datada recente + feed validado). 75 feed + 5 html_listing.
 * Funil completo em public/_tmp_pos2_HASH/relatorio.md.
 */
class Pos2DominiosScSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->getSources() as $data) {
            NewsSource::firstOrCreate(
                ['homepage_url' => $data['homepage_url']],
                $data,
            );
        }
    }

    private function getSources(): array
    {
        $genericListingSelectors = [
            'item' => 'article, .post, .news-item, .card, .entry',
            'title' => 'h1 a, h2 a, h3 a, .title a, .entry-title a',
            'link' => 'h1 a, h2 a, h3 a, .title a, .entry-title a',
            'image' => 'img',
        ];

        $feed = function (string $name, string $homepage, string $feedUrl, string $region) {
            return [
                'name' => $name,
                'homepage_url' => $homepage,
                'source_type' => 'portal',
                'discovery_mode' => 'feed',
                'feed_quality_profile' => 'partial',
                'fetch_detail_mode' => 'when_incomplete',
                'region' => $region,
                'crawling_config' => ['feed_url' => $feedUrl],
                'throttle_config' => ['crawl_interval_min' => 20, 'crawl_interval_max' => 60],
                'active' => true,
                'consecutive_failures' => 0,
            ];
        };

        $listing = function (string $name, string $homepage, string $region) use ($genericListingSelectors) {
            return [
                'name' => $name,
                'homepage_url' => $homepage,
                'source_type' => 'portal',
                'discovery_mode' => 'html_listing',
                'feed_quality_profile' => 'teaser_only',
                'fetch_detail_mode' => 'always',
                'region' => $region,
                'render_js_required' => false,
                'crawling_config' => [
                    'listing_urls' => [$homepage],
                    'selectors' => $genericListingSelectors,
                ],
                'throttle_config' => ['crawl_interval_min' => 30, 'crawl_interval_max' => 120],
                'active' => true,
                'consecutive_failures' => 0,
            ];
        };

        return [
            $feed('Grupo A Sua Voz', 'https://www.grupoasuavoz.com.br', 'https://www.grupoasuavoz.com.br/feed/', 'Oeste'),
            $feed('OESCTV', 'https://tvoesc.com.br', 'https://tvoesc.com.br/feed/', 'Oeste'),
            $feed('BelosF7', 'https://belosf7.com.br', 'https://belosf7.com.br/feed/', 'Oeste'),
            $feed('Atual FM', 'https://atualfm.com.br', 'https://atualfm.com.br/feed/', 'Oeste'),
            $feed('Lance Notícias', 'https://lancenoticias.com.br', 'https://lancenoticias.com.br/feed/', 'Oeste'),
            $feed('Click Xaxim', 'https://clickxaxim.com.br', 'https://clickxaxim.com.br/feed/', 'Oeste'),
            $feed('Caçador Online', 'https://www.cacador.net', 'https://www.cacador.net/rss', 'Oeste'),
            $feed('PortalRBV', 'https://portalrbv.com.br', 'https://portalrbv.com.br/feed/', 'Oeste'),
            $feed('Rádio Catarinense FM', 'https://www.radiocatarinense.com.br', 'https://www.radiocatarinense.com.br/feed/', 'Oeste'),
            $feed('Rádio Líder', 'https://liderfm1075.com.br', 'https://liderfm1075.com.br/feed/', 'Oeste'),
            $feed('Rádio Capinzal', 'https://capinzalfm.com.br', 'https://capinzalfm.com.br/rss', 'Oeste'),
            $feed('Rádio Nativa FM 98,7', 'https://nativacapinzal.net', 'https://nativacapinzal.net/feed/', 'Oeste'),
            $feed('TN Sul', 'https://tnsul.com', 'https://tnsul.com/feed/', 'Sul'),
            $feed('Mais Sul', 'https://maissul.com.br', 'https://maissul.com.br/feed/', 'Sul'),
            $feed('Portal Agora', 'https://agorasul.com.br', 'https://agorasul.com.br/feed/', 'Sul'),
            $feed('Rádio Araranguá FM 95.5', 'https://radioararangua.com.br', 'https://radioararangua.com.br/feed/', 'Sul'),
            $feed('Agora Laguna', 'https://agoralaguna.com.br', 'https://agoralaguna.com.br/feed/', 'Sul'),
            $feed('Folha do Vale', 'https://folhadovale.com.br', 'https://folhadovale.com.br/rss', 'Sul'),
            $feed('Portal de Notícias do Sul do Brasil', 'https://imprensanewssul.com.br', 'https://imprensanewssul.com.br/feed/', 'Sul'),
            $feed('Rádio Fundação Marconi', 'https://radiomarconi.net', 'https://radiomarconi.net/feed/', 'Sul'),
            $feed('Rádio Cruz de Malta', 'https://radiocruzdemalta.com.br', 'https://radiocruzdemalta.com.br/feed/', 'Sul'),
            $feed('Portal sombriosincero.com.br', 'https://sombriosincero.com.br', 'https://sombriosincero.com.br/feed', 'Sul'),
            $feed('Jornal Sul', 'https://jornalsul.com.br', 'https://jornalsul.com.br/feed/', 'Sul'),
            $feed('SBS Online', 'https://sbsonline.com.br', 'https://sbsonline.com.br/feed/', 'Norte'),
            $feed('São Bento Notícias', 'https://www.portalsaobentonoticias.com.br', 'https://www.portalsaobentonoticias.com.br/rss.xml', 'Norte'),
            $feed('Jornal A Gazeta', 'https://gazetasbs.com.br', 'https://gazetasbs.com.br/feed/', 'Norte'),
            $feed('Notícias de Jaraguá do Sul e região', 'https://www.jdv.com.br', 'https://www.jdv.com.br/rss', 'Norte'),
            $feed('Portal de Schroeder', 'https://www.schpost.com.br', 'https://www.schpost.com.br/feed', 'Norte'),
            $feed('» Tribuna da Fronteira', 'https://tribunadafronteira.com.br', 'https://tribunadafronteira.com.br/feed/', 'Norte'),
            $feed('Rede Nova de Comunicação', 'https://www.redenova.fm.br', 'https://www.redenova.fm.br/feed/', 'Norte'),
            $feed('» JMais', 'https://www.jmais.com.br', 'https://www.jmais.com.br/feed/', 'Norte'),
            $feed('Radio Clube de Canoinhas', 'https://radioclubedecanoinhas.com.br', 'https://radioclubedecanoinhas.com.br/feed/', 'Norte'),
            $feed('D+News » Site de notícias da rede Demais FM', 'https://demaisnews.fm.br', 'https://demaisnews.fm.br/feed/', 'Norte'),
            $feed('Portal Folha Babitonga', 'https://www.folhababitonga.com.br', 'https://www.folhababitonga.com.br/feed/', 'Norte'),
            $feed('Jornalnossailha', 'https://jornalnossailha.com.br', 'https://jornalnossailha.com.br/index.php?format=feed&amp;type=rss', 'Norte'),
            $feed('O Iguassú Multimeios', 'https://oiguassu.com.br', 'https://oiguassu.com.br/feed/', 'Norte'),
            $feed('Vvale', 'https://www.vvale.com.br:443', 'https://www.vvale.com.br:443/feed/', 'Norte'),
            $feed('OBlumenauense', 'https://oblumenauense.com.br', 'https://oblumenauense.com.br/feed/', 'Vale do Itajaí'),
            $feed('Informe Blumenau, informação e opinião com notícias de Blume', 'https://www.informeblumenau.com', 'https://www.informeblumenau.com/feed/', 'Vale do Itajaí'),
            $feed('Farol Blumenau', 'https://farolblumenau.com', 'https://farolblumenau.com/feed/', 'Vale do Itajaí'),
            $feed('Rádio Diplomata FM 105,3', 'https://www.diplomatafm.com.br', 'https://www.diplomatafm.com.br/feed/', 'Vale do Itajaí'),
            $feed('Araguaia 104,5 FM', 'https://araguaiabrusque.com.br', 'https://araguaiabrusque.com.br/feed/', 'Vale do Itajaí'),
            $feed('Jornal De Luiz Alves', 'https://www.jornaldeluizalves.com', 'https://www.jornaldeluizalves.com/blog-feed.xml', 'Vale do Itajaí'),
            $feed('Rádio Pomerode', 'https://radiopomerode.com.br', 'https://radiopomerode.com.br/feed/', 'Vale do Itajaí'),
            $feed('Misturebas News', 'https://misturebas.com.br', 'https://misturebas.com.br/feed/', 'Vale do Itajaí'),
            $feed('Jornal do Médio Vale', 'https://jornaldomediovale.com.br', 'https://jornaldomediovale.com.br/feed/', 'Vale do Itajaí'),
            $feed('Redevalenorte', 'https://redevalenorte.com', 'https://redevalenorte.com/feed/', 'Vale do Itajaí'),
            $feed('JP News Litoral', 'https://jpnewslitoral.com.br', 'https://jpnewslitoral.com.br/feed/', 'Vale do Itajaí'),
            $feed('Noticias Balneario Camboriu', 'https://diariodacidade.com.br', 'https://diariodacidade.com.br/feed/', 'Vale do Itajaí'),
            $feed('Click Camboriú', 'https://clickcamboriu.com.br', 'https://clickcamboriu.com.br/feed', 'Vale do Itajaí'),
            $feed('Jornal do Comércio', 'https://jornaljc.com.br', 'https://jornaljc.com.br/feed/', 'Vale do Itajaí'),
            $feed('Marazul News', 'https://marazulnews.com.br', 'https://marazulnews.com.br/feed/', 'Vale do Itajaí'),
            $feed('Penha Online', 'https://penhaonline.com', 'https://penhaonline.com/feed/', 'Vale do Itajaí'),
            $feed('Notícias Floripa', 'https://noticiasfloripa.com', 'https://noticiasfloripa.com/feed/', 'Grande Florianópolis'),
            $feed('Notícias de Florianópolis', 'https://www.deolhonailha.com.br', 'https://www.deolhonailha.com.br/feed/', 'Grande Florianópolis'),
            $feed('Folha de Florianópolis', 'https://www.folhadeflorianopolis.com.br', 'https://www.folhadeflorianopolis.com.br/rss.xml', 'Grande Florianópolis'),
            $feed('Jornalsuldailha', 'https://jornalsuldailha.com.br', 'https://jornalsuldailha.com.br/feed/', 'Grande Florianópolis'),
            $feed('Portal Norte da Ilha', 'https://portalnortedailha.com.br', 'https://portalnortedailha.com.br/feed', 'Grande Florianópolis'),
            $feed('Portal Catarinas', 'https://catarinas.info', 'https://catarinas.info/feed/', 'Grande Florianópolis'),
            $feed('Santa Catarina em Pauta', 'https://www.santacatarinaempauta.com.br', 'https://www.santacatarinaempauta.com.br/wp-json/wp/v2/posts?per_page=1', 'Grande Florianópolis'),
            $feed('Portal Making Of', 'https://portalmakingof.com.br', 'https://portalmakingof.com.br/feed/', 'Grande Florianópolis'),
            $feed('TVBV ONLINE', 'https://www.tvbv.com.br', 'https://www.tvbv.com.br/feed/', 'Grande Florianópolis'),
            $feed('Tudo Aqui SC', 'https://tudoaquisc.com.br', 'https://tudoaquisc.com.br/feed/', 'Grande Florianópolis'),
            $feed('Biguá News', 'https://biguanews.com.br', 'https://biguanews.com.br/feed/', 'Grande Florianópolis'),
            $feed('Empresa Biguaçuense de Notícias', 'https://empresabiguacuensedenoticias.com', 'https://empresabiguacuensedenoticias.com/feed/', 'Grande Florianópolis'),
            $feed('Jornal Comunidade Santa Catarina', 'https://www.jornalcomunidadesantacatarina.com', 'https://www.jornalcomunidadesantacatarina.com/blog-feed.xml', 'Grande Florianópolis'),
            $feed('O Trentino', 'https://otrentino.com.br', 'https://otrentino.com.br/feed/', 'Grande Florianópolis'),
            $feed('Jornal Alfredo Wagner Online', 'https://jornalaw.com.br', 'https://jornalaw.com.br/feed/', 'Grande Florianópolis'),
            $feed('Notícia No Ato', 'https://noticianoato.com.br', 'https://noticianoato.com.br/feed', 'Serra'),
            $feed('CLMais', 'https://clmais.com.br', 'https://clmais.com.br/rss', 'Serra'),
            $feed('Lages Diário │ Notícias de Lages e região', 'https://www.lagesdiario.com.br', 'https://www.blogger.com/feeds/341382066234363811/posts/default', 'Serra'),
            $feed('Notiserra SC', 'https://notiserrasc.com.br', 'https://notiserrasc.com.br/feed/', 'Serra'),
            $feed('Portal Coroado', 'https://portalcoroado.com.br/home', 'https://portalcoroado.com.br/home/feed/', 'Serra'),
            $feed('Rádio Alegria FM 87,9', 'https://www.alegriafm.net', 'https://www.alegriafm.net/feed/', 'Serra'),
            $feed('Rádio Alvorada 94.5 FM', 'https://alvorada945.com.br', 'https://alvorada945.com.br/feed/', 'Serra'),
            $listing('Oeste SC Notícias', 'https://oestescnoticias.com.br', 'Oeste'),
            $listing('TiviNet', 'https://www.tivinet.com.br', 'Oeste'),
            $listing('Portal CDR', 'https://www.portalcdr.com.br', 'Oeste'),
            $listing('Jornal do Momento', 'https://domomento.com.br', 'Serra'),
            $listing('ViaTV', 'https://viatv.com.br', 'Serra'),
            // Re-probe 2026-06-10 (falso-mortos do 1º QA — bloqueio por UA/fingerprint):
            $feed('Portal GuiaTem Abelardo Luz', 'https://www.guiatemabelardoluz.com.br', 'https://www.guiatemabelardoluz.com.br/feed', 'Oeste'),
            // (saojoaquimonline e portalitapema já existiam nas fontes originais —
            // estavam mortos pro UA antigo; recuperados pela troca de UA, sem seed novo)
        ];
    }
}
