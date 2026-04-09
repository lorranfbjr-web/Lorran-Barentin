<?php

namespace App\Modules\NewsRadar\Enums;

enum SourceRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
}
