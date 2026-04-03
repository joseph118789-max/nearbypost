<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FeedController;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('feed')->middleware(['throttle:60,1'])->group(function () {
    Route::get('/default', [FeedController::class, 'default'])->name('feed.default');
    Route::get('/category/{slug}', [FeedController::class, 'byCategory'])->name('feed.category');
    Route::get('/nearby', [FeedController::class, 'nearby'])->name('feed.nearby');
    Route::get('/filter', [FeedController::class, 'filter'])->name('feed.filter');
});
