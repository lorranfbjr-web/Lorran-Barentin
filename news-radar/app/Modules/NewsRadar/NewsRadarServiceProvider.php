<?php

namespace App\Modules\NewsRadar;

use App\Modules\NewsRadar\Console\DispatchNewsSourcesCommand;
use Illuminate\Support\ServiceProvider;

class NewsRadarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/news_radar.php', 'news_radar');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchNewsSourcesCommand::class,
            ]);
        }
    }
}
