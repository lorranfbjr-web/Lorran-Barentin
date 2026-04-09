<?php

namespace App\Modules\NewsRadar\Enums;

enum DiscoveryMode: string
{
    case Auto = 'auto';
    case Feed = 'feed';
    case Sitemap = 'sitemap';
    case HtmlListing = 'html_listing';
}
