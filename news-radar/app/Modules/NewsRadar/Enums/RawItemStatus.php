<?php

namespace App\Modules\NewsRadar\Enums;

enum RawItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Promoted = 'promoted';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
