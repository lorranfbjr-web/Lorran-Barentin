<?php

namespace App\Modules\NewsRadar\Enums;

enum EnrichmentStatus: string
{
    case None = 'none';
    case EnrichedL1 = 'enriched_l1';
    case EnrichedL2 = 'enriched_l2';
    case EnrichmentFailed = 'enrichment_failed';
}
