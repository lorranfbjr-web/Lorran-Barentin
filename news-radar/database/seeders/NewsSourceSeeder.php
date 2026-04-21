<?php

namespace Database\Seeders;

use App\Modules\NewsRadar\Models\NewsSource;
use App\Modules\NewsRadar\Models\NewsTheme;
use Illuminate\Database\Seeder;

class NewsSourceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedThemes();
        $this->seedSources();
    }

    private function seedThemes(): void
    {
        $themes = [
            'politica' => 'Política',
            'policia' => 'Polícia',
            'esporte' => 'Esporte',
            'economia' => 'Economia',
            'saude' => 'Saúde',
            'educacao' => 'Educação',
            'cultura' => 'Cultura',
            'tecnologia' => 'Tecnologia',
            'meio_ambiente' => 'Meio Ambiente',
            'transporte' => 'Transporte',
            'sociedade' => 'Sociedade',
            'internacional' => 'Internacional',
            'outro' => 'Outro',
        ];

        foreach ($themes as $slug => $label) {
            NewsTheme::firstOrCreate(['slug' => $slug], ['label' => $label]);
        }
    }

    private function seedSources(): void
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
        // Seletores genericos padrao para fontes em modo html_listing.
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

        $listing = function (string $name, string $homepage, string $region, ?string $listingUrl = null, bool $renderJs = false) use ($genericListingSelectors) {
            return [
                'name' => $name,
                'homepage_url' => $homepage,
                'source_type' => 'portal',
                'discovery_mode' => 'html_listing',
                'feed_quality_profile' => 'teaser_only',
                'fetch_detail_mode' => 'always',
                'region' => $region,
                'render_js_required' => $renderJs,
                'crawling_config' => [
                    'listing_urls' => [$listingUrl ?? $homepage],
                    'selectors' => $genericListingSelectors,
                ],
                'throttle_config' => ['crawl_interval_min' => 30, 'crawl_interval_max' => 120],
                'active' => true,
                'consecutive_failures' => 0,
            ];
        };

        $oficial = function (string $name, string $homepage, ?string $listingUrl = null) use ($genericListingSelectors) {
            return [
                'name' => $name,
                'homepage_url' => $homepage,
                'source_type' => 'agencia',
                'discovery_mode' => 'html_listing',
                'feed_quality_profile' => 'teaser_only',
                'fetch_detail_mode' => 'always',
                'region' => 'Estadual',
                'crawling_config' => [
                    'listing_urls' => [$listingUrl ?? $homepage],
                    'selectors' => $genericListingSelectors,
                ],
                'throttle_config' => ['crawl_interval_min' => 60, 'crawl_interval_max' => 240],
                'active' => true,
                'consecutive_failures' => 0,
            ];
        };

        return [
            // ===== ESTADUAL / TIER 1 — feeds =====
            $feed('ND Mais',           'https://ndmais.com.br',   'https://ndmais.com.br/feed/',   'Estadual'),
            $feed('OCP News',          'https://ocp.news',        'https://ocp.news/feed',         'Estadual'),
            $feed('SCC10',             'https://scc10.com.br',    'https://scc10.com.br/feed',     'Estadual'),

            // ===== ESTADUAL / TIER 1 — sem feed detectado =====
            $listing('NSC Total',          'https://nsctotal.com.br',    'Estadual'),
            $listing('G1 Santa Catarina',  'https://g1.globo.com/sc',    'Estadual'),
            $listing('Diário Catarinense', 'https://dc.com.br',          'Estadual'),

            // ===== VALE DO ITAJAI =====
            $feed('O Município (Brusque)',    'https://omunicipio.com.br',         'https://omunicipio.com.br/feed',            'Vale do Itajaí'),
            $feed('Vale do Itajaí Notícias',  'https://valedoitajainoticias.com.br','https://valedoitajainoticias.com.br/feed/', 'Vale do Itajaí'),
            $feed('RBA TV (Blumenau)',        'https://rbatv.com.br',              'https://rbatv.com.br/feed',                 'Vale do Itajaí'),
            $feed('Guabiruba Zeitung',        'https://guabirubazeitung.com.br',   'https://guabirubazeitung.com.br/feed/',     'Vale do Itajaí'),
            $listing('Diário do Iguaçu',      'https://diariodoiguacu.com.br',     'Vale do Itajaí'),

            // ===== VALE DO RIO TIJUCAS =====
            $feed('VipSocial',        'https://vipsocial.com.br',    'https://vipsocial.com.br/rss.xml',  'Vale do Rio Tijucas'),
            $listing('TopElegance',   'https://topelegance.com.br',  'Vale do Rio Tijucas'),

            // ===== BALNEARIO CAMBORIU =====
            $feed('Página 3',       'https://pagina3.com.br',    'https://pagina3.com.br/feed/', 'Balneário Camboriú'),
            $feed('Camboriú News',  'https://camboriu.news',     'https://camboriu.news/feed/',  'Balneário Camboriú'),

            // ===== LITORAL NORTE =====
            $feed('Portal Itapema',         'https://portalitapema.com',         'https://portalitapema.com/feed',                  'Litoral Norte'),
            $feed('Lance Itapema',          'https://lanceitapema.com.br',       'https://lanceitapema.com.br/feed/',               'Litoral Norte'),
            $feed('Hora de Porto Belo',     'https://horadeportobelo.com.br',    'https://horadeportobelo.com.br/feed/',            'Litoral Norte'),
            $feed('Jornal de Navegantes',   'https://jornaldenavegantes.com.br', 'https://jornaldenavegantes.com.br/rss.xml',       'Litoral Norte'),
            $feed('Barra Velha Online',     'https://barravelhaonline.com.br',   'https://www.barravelhaonline.com.br/blog-feed.xml','Litoral Norte'),

            // ===== GRANDE FLORIANOPOLIS =====
            $feed('SJ Agora (São José)',     'https://sjagora.com.br',      'https://sjagora.com.br/rss',      'Grande Florianópolis'),
            $feed('Biguaçu Tá On',           'https://biguataon.com.br',    'https://biguataon.com.br/feed/',  'Grande Florianópolis'),
            $listing('Informe Floripa',      'https://informefloripa.com.br','Grande Florianópolis', null, true),

            // ===== NORTE =====
            $feed('Notícias Joinville',      'https://noticiasjoinville.com.br',      'https://noticiasjoinville.com.br/feed/',   'Norte'),
            $feed('Aconteceu em Joinville',  'https://aconteceuemjoinville.com.br',   'https://aconteceuemjoinville.com.br/feed/','Norte'),
            $feed('AJ Notícias',             'https://ajnoticias.com.br',             'https://ajnoticias.com.br/rss.xml',        'Norte'),
            $listing('A Notícia (Joinville)','https://an.com.br',                     'Norte'),

            // ===== SUL =====
            $feed('Notisul (Tubarão)',  'https://notisul.com.br',   'https://www.notisul.com.br/feed/', 'Sul'),
            $listing('Engeplus (Criciúma)', 'https://engeplus.com.br', 'Sul'),

            // ===== SERRA =====
            $feed('Notícias Online Lages', 'https://noticiasonlinelages.com.br', 'https://noticiasonlinelages.com.br/feed/', 'Serra'),
            $feed('São Joaquim Online',    'https://saojoaquimonline.com.br',    'https://saojoaquimonline.com.br/feed/',    'Serra'),

            // ===== OUTROS =====
            $feed('2linhas', 'https://2linhas.com', 'https://2linhas.com/feed/', 'Estadual'),

            // ===== OFICIAIS / AGENCIAS — html_listing =====
            $oficial('Polícia Civil de SC',      'https://pc.sc.gov.br'),
            $oficial('Corpo de Bombeiros de SC', 'https://cbm.sc.gov.br'),
            $oficial('Defesa Civil de SC',       'https://defesacivil.sc.gov.br'),
            $oficial('Ministério Público de SC', 'https://mpsc.mp.br'),
            $oficial('Tribunal de Justiça de SC','https://tjsc.jus.br'),
        ];
    }
}
