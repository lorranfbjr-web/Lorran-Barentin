<?php

namespace App\Modules\NewsRadar\Models;

use App\Modules\NewsRadar\Enums\DiscoveryMode;
use App\Modules\NewsRadar\Enums\FeedQualityProfile;
use App\Modules\NewsRadar\Enums\FetchDetailMode;
use App\Modules\NewsRadar\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsSource extends Model
{
    protected $fillable = [
        'name', 'homepage_url', 'active', 'source_type', 'discovery_mode',
        'feed_quality_profile', 'fetch_detail_mode', 'crawling_config',
        'throttle_config', 'timezone_default', 'date_formats', 'render_js_required',
        'region', 'next_sync_at', 'sync_locked_until', 'consecutive_failures', 'success_rate',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'source_type' => SourceType::class,
            'discovery_mode' => DiscoveryMode::class,
            'feed_quality_profile' => FeedQualityProfile::class,
            'fetch_detail_mode' => FetchDetailMode::class,
            'crawling_config' => 'array',
            'throttle_config' => 'array',
            'date_formats' => 'array',
            'render_js_required' => 'boolean',
            'next_sync_at' => 'datetime',
            'sync_locked_until' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(NewsSourceRun::class);
    }

    public function rawItems(): HasMany
    {
        return $this->hasMany(NewsRawItem::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(NewsItem::class);
    }

    public function getThrottleMin(): int
    {
        return $this->throttle_config['crawl_interval_min'] ?? 30;
    }

    public function getThrottleMax(): int
    {
        return $this->throttle_config['crawl_interval_max'] ?? 120;
    }

    public function isLocked(): bool
    {
        return $this->sync_locked_until && $this->sync_locked_until->isFuture();
    }

    public function isDueForSync(): bool
    {
        return $this->active
            && !$this->isLocked()
            && ($this->next_sync_at === null || $this->next_sync_at->isPast());
    }

    public function recordSuccess(int $newItems): void
    {
        $interval = $newItems > 0 ? $this->getThrottleMin() : $this->getThrottleMax();

        $this->update([
            'consecutive_failures' => 0,
            'next_sync_at' => now()->addMinutes($interval),
            'sync_locked_until' => null,
        ]);
    }

    public function recordFailure(): void
    {
        $failures = $this->consecutive_failures + 1;

        $this->update([
            'consecutive_failures' => $failures,
            'active' => $failures < 5,
            'next_sync_at' => now()->addMinutes($this->getThrottleMax()),
            'sync_locked_until' => null,
        ]);
    }

    public function acquireLock(int $timeoutMinutes = 10): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        $this->update(['sync_locked_until' => now()->addMinutes($timeoutMinutes)]);
        return true;
    }

    public function releaseLock(): void
    {
        $this->update(['sync_locked_until' => null]);
    }
}
