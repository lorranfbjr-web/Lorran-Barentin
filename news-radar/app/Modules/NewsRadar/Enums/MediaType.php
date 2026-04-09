<?php

namespace App\Modules\NewsRadar\Enums;

enum MediaType: string
{
    case Hero = 'hero';
    case Gallery = 'gallery';
    case Video = 'video';
    case Embed = 'embed';
}
