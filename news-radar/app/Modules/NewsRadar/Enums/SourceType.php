<?php

namespace App\Modules\NewsRadar\Enums;

enum SourceType: string
{
    case Portal = 'portal';
    case Prefeitura = 'prefeitura';
    case Blog = 'blog';
    case Agencia = 'agencia';
    case Whatsapp = 'whatsapp';
}
