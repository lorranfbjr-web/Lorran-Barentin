<?php

namespace App\Modules\NewsRadar\Enums;

enum DiscoveryRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
