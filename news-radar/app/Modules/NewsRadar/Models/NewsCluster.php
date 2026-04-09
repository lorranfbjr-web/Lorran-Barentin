<?php

namespace App\Modules\NewsRadar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NewsCluster extends Model
{
    protected $fillable = ['title', 'representative_item_id', 'items_count'];

    public function representativeItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class, 'representative_item_id');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(NewsItem::class, 'news_cluster_items')
            ->withPivot('similarity_score')
            ->withTimestamps();
    }
}
