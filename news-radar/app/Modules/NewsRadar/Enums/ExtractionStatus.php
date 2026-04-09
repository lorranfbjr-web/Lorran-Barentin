<?php

namespace App\Modules\NewsRadar\Enums;

enum ExtractionStatus: string
{
    case Pending = 'pending';
    case Extracted = 'extracted';
    case ExtractionFailed = 'extraction_failed';
}
