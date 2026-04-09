<?php

namespace App\Modules\NewsRadar\Enums;

enum FetchDetailMode: string
{
    case Never = 'never';
    case WhenIncomplete = 'when_incomplete';
    case Always = 'always';
}
