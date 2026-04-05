<?php

use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\Admin\SubscriberController;
use App\Http\Controllers\Api\Admin\BroadcastGroupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/feed', [FeedController::class, 'index']);
    Route::get('/feed/default', [FeedController::class, 'default']);
    Route::get('/feed/category/{slug}', [FeedController::class, 'byCategory']);
    Route::get('/feed/nearby', [FeedController::class, 'nearby']);
    Route::get('/feed/filter', [FeedController::class, 'filter']);
    Route::get('/feed/{category}', [FeedController::class, 'byCategory']);
});

Route::post('/report-content', [ReportController::class, 'store']);
Route::post('/internal/ingest/news', [IngestController::class, 'ingest']);
Route::post('/internal/ingest/batch', [IngestController::class, 'ingestBatch']);

Route::prefix('admin')->group(function () {
    Route::get('/subscribers', [SubscriberController::class, 'index']);
    Route::post('/subscribers', [SubscriberController::class, 'store']);
    Route::put('/subscribers/{id}', [SubscriberController::class, 'update']);
    Route::delete('/subscribers/{id}', [SubscriberController::class, 'destroy']);
    Route::get('/subscribers/{id}/preferences', [SubscriberController::class, 'getPreferences']);
    Route::put('/subscribers/{id}/preferences', [SubscriberController::class, 'updatePreferences']);
    Route::post('/subscribers/bulk-update-preferences', [SubscriberController::class, 'bulkUpdatePreferences']);
    Route::get('/broadcast-groups', [BroadcastGroupController::class, 'index']);
    Route::post('/broadcast-groups', [BroadcastGroupController::class, 'store']);
    Route::put('/broadcast-groups/{id}', [BroadcastGroupController::class, 'update']);
    Route::delete('/broadcast-groups/{id}', [BroadcastGroupController::class, 'destroy']);
});
