<?php

namespace App\Modules\NewsRadar\Models;

use App\Modules\NewsRadar\Enums\RawItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NewsRawItem extends Model
{
    protected $fillable = [
        'news_source_id', 'news_source_run_id', 'raw_url', 'normalized_url',
        'url_hash', 'guid', 'raw_payload', 'processing_status',
        'fetch_attempts', 'last_seen_at', 'seen_count',
    ];

    protected function casts(): array
    {
        return [
            'processing_status' => RawItemStatus::class,
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'news_source_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(NewsSourceRun::class, 'news_source_run_id');
    }

    public function newsItem(): HasOne
    {
        return $this->hasOne(NewsItem::class);
    }
}
