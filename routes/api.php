<?php

use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Admin\SubscriberController;
use App\Http\Controllers\Api\Admin\BroadcastGroupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check
Route::get('/health', [HealthController::class, 'index']);

// Feed endpoints (existing)
Route::get('/feed', [FeedController::class, 'index']);
Route::get('/feed/{category}', [FeedController::class, 'byCategory']);

// Report content endpoint
Route::post('/report-content', [ReportController::class, 'store']);

// Internal ingestion webhook
Route::post('/internal/ingest/news', [IngestController::class, 'ingest']);

// Admin API routes
Route::prefix('admin')->group(function () {
    // Subscriber routes
    Route::get('/subscribers', [SubscriberController::class, 'index']);
    Route::post('/subscribers', [SubscriberController::class, 'store']);
    Route::put('/subscribers/{id}', [SubscriberController::class, 'update']);
    Route::delete('/subscribers/{id}', [SubscriberController::class, 'destroy']);
    
    // Subscriber preference management
    Route::get('/subscribers/{id}/preferences', [SubscriberController::class, 'getPreferences']);
    Route::put('/subscribers/{id}/preferences', [SubscriberController::class, 'updatePreferences']);
    
    // Bulk preference updates
    Route::post('/subscribers/bulk-update-preferences', [SubscriberController::class, 'bulkUpdatePreferences']);
    
    // Broadcast Group routes
    Route::get('/broadcast-groups', [BroadcastGroupController::class, 'index']);
    Route::post('/broadcast-groups', [BroadcastGroupController::class, 'store']);
    Route::put('/broadcast-groups/{id}', [BroadcastGroupController::class, 'update']);
    Route::delete('/broadcast-groups/{id}', [BroadcastGroupController::class, 'destroy']);
});
