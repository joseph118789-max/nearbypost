<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SubscriberController;
use App\Http\Controllers\Api\BroadcastGroupController;
use App\Http\Controllers\Api\IngestController;

Route::prefix('feed')->middleware(['throttle:60,1'])->group(function () {
    Route::get('/default', [FeedController::class, 'default'])->name('feed.default');
    Route::get('/category/{slug}', [FeedController::class, 'byCategory'])->name('feed.category');
    Route::get('/nearby', [FeedController::class, 'nearby'])->name('feed.nearby');
    Route::get('/filter', [FeedController::class, 'filter'])->name('feed.filter');
});

Route::post('/report-content', [ReportController::class, 'store'])->name('report-content');

Route::prefix('internal')->group(function () {
    Route::post('/ingest/news', [IngestController::class, 'ingestNews'])->name('ingest.news');
});

Route::prefix('admin')->group(function () {
    Route::resource('subscribers', SubscriberController::class);
    Route::resource('broadcast-groups', BroadcastGroupController::class);
});
