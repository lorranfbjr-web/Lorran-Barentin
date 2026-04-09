<?php

namespace App\Modules\NewsRadar\Models;

use App\Modules\NewsRadar\Enums\Urgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsItemAiMetadata extends Model
{
    protected $table = 'news_item_ai_metadata';

    protected $fillable = [
        'news_item_id', 'city', 'state_abbr', 'news_theme_id', 'urgency',
        'relevance_score', 'entities', 'five_ws', 'suggested_titles',
        'summary_bullets', 'enrichment_level',
    ];

    protected function casts(): array
    {
        return [
            'urgency' => Urgency::class,
            'entities' => 'array',
            'five_ws' => 'array',
            'suggested_titles' => 'array',
            'summary_bullets' => 'array',
        ];
    }

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(NewsTheme::class, 'news_theme_id');
    }
}
