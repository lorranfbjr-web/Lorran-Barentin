<?php

namespace App\Modules\NewsRadar\Enums;

enum Urgency: string
{
    case Baixa = 'baixa';
    case Media = 'media';
    case Alta = 'alta';
}
