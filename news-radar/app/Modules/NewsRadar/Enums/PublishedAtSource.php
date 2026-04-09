<?php

namespace App\Modules\NewsRadar\Enums;

enum PublishedAtSource: string
{
    case Rss = 'rss';
    case JsonLd = 'jsonld';
    case OgTag = 'og_tag';
    case TimeTag = 'time_tag';
    case TextPattern = 'text_pattern';
    case Manual = 'manual';
}
