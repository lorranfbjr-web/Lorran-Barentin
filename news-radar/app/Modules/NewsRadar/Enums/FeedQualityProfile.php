<?php

namespace App\Modules\NewsRadar\Enums;

enum FeedQualityProfile: string
{
    case Full = 'full';
    case Partial = 'partial';
    case TeaserOnly = 'teaser_only';
}
