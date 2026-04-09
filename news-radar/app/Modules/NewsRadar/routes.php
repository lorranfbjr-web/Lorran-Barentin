<?php

use App\Modules\NewsRadar\Http\Controllers\NewsItemController;
use App\Modules\NewsRadar\Http\Controllers\NewsSourceController;
use App\Modules\NewsRadar\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/news-radar')->group(function () {

    // Dashboard / Stats
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // News Items
    Route::get('/items', [NewsItemController::class, 'index']);
    Route::get('/items/{id}', [NewsItemController::class, 'show']);
    Route::get('/items/trending', [NewsItemController::class, 'trending']);

    // News Sources
    Route::get('/sources', [NewsSourceController::class, 'index']);
    Route::get('/sources/{id}', [NewsSourceController::class, 'show']);
    Route::post('/sources', [NewsSourceController::class, 'store']);
    Route::put('/sources/{id}', [NewsSourceController::class, 'update']);
    Route::patch('/sources/{id}/toggle', [NewsSourceController::class, 'toggle']);
    Route::post('/sources/{id}/fetch-now', [NewsSourceController::class, 'fetchNow']);
    Route::get('/sources/{id}/runs', [NewsSourceController::class, 'runs']);

    // Themes
    Route::get('/themes', [NewsItemController::class, 'themes']);

    // Bulk operations
    Route::post('/collect-all', [NewsSourceController::class, 'collectAll']);

    // Regions
    Route::get('/regions', [NewsSourceController::class, 'regions']);
});
