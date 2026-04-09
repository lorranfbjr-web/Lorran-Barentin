<?php

namespace App\Modules\NewsRadar\Enums;

enum ContentSource: string
{
    case FeedOnly = 'feed_only';
    case FeedPlusHtml = 'feed_plus_html';
    case HtmlOnly = 'html_only';
}
