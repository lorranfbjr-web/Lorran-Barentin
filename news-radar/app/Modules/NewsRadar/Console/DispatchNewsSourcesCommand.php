<?php

namespace App\Modules\NewsRadar\Console;

use App\Modules\NewsRadar\Jobs\FetchNewsSourceJob;
use App\Modules\NewsRadar\Models\NewsSource;
use Illuminate\Console\Command;

class DispatchNewsSourcesCommand extends Command
{
    protected $signature = 'news-radar:dispatch';
    protected $description = 'Dispatch fetch jobs for news sources that are due for sync';

    public function handle(): void
    {
        $sources = NewsSource::where('active', true)
            ->where(function ($q) {
                $q->whereNull('next_sync_at')
                  ->orWhere('next_sync_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('sync_locked_until')
                  ->orWhere('sync_locked_until', '<=', now());
            })
            ->get();

        $dispatched = 0;

        foreach ($sources as $source) {
            FetchNewsSourceJob::dispatch($source->id);
            $dispatched++;
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} source(s) for fetching.");
        }
    }
}
