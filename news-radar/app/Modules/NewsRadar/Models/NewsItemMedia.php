<?php

namespace App\Modules\NewsRadar\Models;

use App\Modules\NewsRadar\Enums\MediaType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsItemMedia extends Model
{
    protected $table = 'news_item_media';

    protected $fillable = [
        'news_item_id', 'type', 'url', 'alt_text', 'width', 'height', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
        ];
    }

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
