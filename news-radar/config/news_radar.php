<?php

return [
    'ai' => [
        'classification_model' => env('NEWS_RADAR_CLASSIFICATION_MODEL', 'gpt-4o-mini'),
        'enrichment_model' => env('NEWS_RADAR_ENRICHMENT_MODEL', 'gpt-4o-mini'),
    ],

    'http' => [
        'user_agent' => env('NEWS_RADAR_USER_AGENT', 'JornalRazaoBot/1.0 (+https://jornalrazao.com/bot)'),
        'timeout' => (int) env('NEWS_RADAR_HTTP_TIMEOUT', 15),
        'retry_times' => (int) env('NEWS_RADAR_HTTP_RETRIES', 2),
    ],

    'throttle' => [
        'default_min_interval' => 15,  // minutes
        'default_max_interval' => 60,  // minutes
        'lock_ttl' => 10,             // minutes
    ],

    'cleanup' => [
        'max_age_days' => 30,
    ],
];
