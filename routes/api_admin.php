<?php

use App\Http\Controllers\Api\Admin\SubscriberController;
use App\Http\Controllers\Api\Admin\BroadcastGroupController;
use Illuminate\Support\Facades\Route;

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