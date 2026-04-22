<?php

namespace Database\Seeders;

use App\Modules\NewsRadar\Models\NewsSource;
use Illuminate\Database\Seeder;

class CatarinensesExtraSeeder extends Seeder
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

        return [
            // === 18 COM RSS ===
            $feed('Portal Menina',            'https://portalmenina.com.br',         'https://portalmenina.com.br/feed',         'Grande Florianópolis'),
            $feed('Eder Luiz',                'https://ederluiz.com.vc',             'https://ederluiz.com.vc/feed',             'Oeste'),
            $feed('Visor Notícias',           'https://visornoticias.com.br',        'https://visornoticias.com.br/feed',        'Santa Catarina'),
            $feed('Rádio Clube 88',           'https://radioclube88.com.br',         'https://radioclube88.com.br/feed',         'Santa Catarina'),
            $feed('Rádio Super',              'https://radiosuper.com.br',           'https://radiosuper.com.br/feed',           'Santa Catarina'),
            $feed('Agora Floripa',            'https://www.agorafloripa.com.br',     'https://www.agorafloripa.com.br/feed',     'Grande Florianópolis'),
            $feed('CBN Vale do Itajaí',       'https://www.cbnvaledoitajai.com.br',  'https://www.cbnvaledoitajai.com.br/feed',  'Vale do Itajaí'),
            $feed('Blog do Jaime',            'https://blogdojaime.com.br',          'https://blogdojaime.com.br/feed',          'Santa Catarina'),
            $feed('ClicRDC',                  'https://clicrdc.com.br',              'https://clicrdc.com.br/feed',              'Santa Catarina'),
            $feed('Folha do Desbravador',     'https://folhadesbravador.com.br',     'https://folhadesbravador.com.br/feed',     'Santa Catarina'),
            $feed('Chapecó Online',           'https://www.chapecoonline.com.br',    'https://www.chapecoonline.com.br/feed',    'Oeste'),
            $feed('Portal Ah Hora',           'https://portalahora.com.br',          'https://portalahora.com.br/feed',          'Santa Catarina'),
            $feed('SC em Pauta',              'https://scempauta.com.br',            'https://scempauta.com.br/feed',            'Santa Catarina'),
            $feed('GCD',                      'https://www.gcd.com.br',              'https://www.gcd.com.br/feed',              'Santa Catarina'),
            $feed('Rádio Mirador',            'https://radiomirador.com.br',         'https://radiomirador.com.br/feed',         'Santa Catarina'),
            $feed('Aquidaba Notícia',         'https://www.aquidabanoticia.com.br',  'https://www.aquidabanoticia.com.br/rss.xml', 'Santa Catarina'),
            $feed('Abre Olho Notícias',       'https://abreolhonoticias.com.br',     'https://abreolhonoticias.com.br/feed',     'Santa Catarina'),
            $feed('Guararema News',           'https://guararemanews.com.br',        'https://guararemanews.com.br/feed',        'Santa Catarina'),

            // === 12 SEM RSS (HTML listing) ===
            $listing('Oeste Mais',             'https://www.oestemais.com',           'Oeste'),
            $listing('Peperi',                 'https://peperi.com.br',               'Oeste'),
            $listing('RC FM',                  'https://rc.fm.br',                    'Santa Catarina', 'https://rc.fm.br/web/'),
            $listing('Diarinho',               'https://diarinho.net',                'Vale do Itajaí'),
            $listing('Joinville Informações',  'https://www.joinvilleinformacoes.com.br', 'Norte'),
            $listing('Di Regional',            'https://diregional.com.br',           'Santa Catarina'),
            $listing('Notícias Imbituba',      'https://noticiasimbituba.com.br',     'Sul'),
            $listing('Portal Click Sul',       'https://portalclicksul.com.br',       'Sul'),
            $listing('RSC Portal',             'https://rscportal.com.br',            'Santa Catarina'),
            $listing('Cruzeiro do Vale',       'https://www.cruzeirodovale.com.br',   'Vale do Itajaí'),
            $listing('Jornal Metas',           'https://www.jornalmetas.com.br',      'Santa Catarina'),
            $listing('NSC Total',              'https://www.nsctotal.com.br',         'Santa Catarina'),
        ];
    }
}
