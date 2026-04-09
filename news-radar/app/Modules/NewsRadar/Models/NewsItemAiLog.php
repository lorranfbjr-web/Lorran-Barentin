<?php

namespace App\Modules\NewsRadar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsItemAiLog extends Model
{
    protected $table = 'news_item_ai_logs';

    protected $fillable = [
        'news_item_id', 'operation', 'model', 'status', 'attempt',
        'strategy', 'input_tokens', 'output_tokens',
        'error_category', 'error_message', 'duration_ms',
    ];

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
