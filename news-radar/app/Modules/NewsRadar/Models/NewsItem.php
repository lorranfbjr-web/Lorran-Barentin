<?php

namespace App\Modules\NewsRadar\Models;

use App\Modules\NewsRadar\Enums\ContentSource;
use App\Modules\NewsRadar\Enums\EnrichmentStatus;
use App\Modules\NewsRadar\Enums\ExtractionStatus;
use App\Modules\NewsRadar\Enums\PublishedAtSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NewsItem extends Model
{
    protected $fillable = [
        'news_source_id', 'news_raw_item_id', 'title', 'subtitle',
        'author_raw', 'author_normalized', 'body_html', 'body_text',
        'hero_image_url', 'url', 'url_hash',
        'published_at_raw', 'published_at_parsed', 'published_at_utc',
        'published_at_timezone', 'published_at_source',
        'extraction_completeness', 'content_source',
        'extraction_status', 'enrichment_status',
        'field_sources', 'categories', 'duplicate_of_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at_parsed' => 'datetime',
            'published_at_utc' => 'datetime',
            'published_at_source' => PublishedAtSource::class,
            'content_source' => ContentSource::class,
            'extraction_status' => ExtractionStatus::class,
            'enrichment_status' => EnrichmentStatus::class,
            'field_sources' => 'array',
            'categories' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'news_source_id');
    }

    public function rawItem(): BelongsTo
    {
        return $this->belongsTo(NewsRawItem::class, 'news_raw_item_id');
    }

    public function aiMetadata(): HasOne
    {
        return $this->hasOne(NewsItemAiMetadata::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(NewsItemMedia::class);
    }

    public function aiLogs(): HasMany
    {
        return $this->hasMany(NewsItemAiLog::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }
}
