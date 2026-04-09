<?php

namespace App\Modules\NewsRadar\Models;

use App\Modules\NewsRadar\Enums\SourceRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsSourceRun extends Model
{
    protected $fillable = [
        'news_source_id', 'status', 'discovery_mode_used', 'items_found',
        'items_new', 'items_updated', 'duration_ms', 'error_message',
        'metadata', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SourceRunStatus::class,
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'news_source_id');
    }

    public function rawItems(): HasMany
    {
        return $this->hasMany(NewsRawItem::class);
    }
}
