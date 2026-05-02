<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\HomeController;

Route::get('/', [HomeController::class, 'index']);
Route::get('/by_category', [HomeController::class, 'byCategory']);

Route::prefix('feed')->middleware(['throttle:60,1'])->group(function () {
    Route::get('/default', [FeedController::class, 'default'])->name('feed.default');
    Route::get('/category/{slug}', [FeedController::class, 'byCategory'])->name('feed.category');
    Route::get('/nearby', [FeedController::class, 'nearby'])->name('feed.nearby');
    Route::get('/filter', [FeedController::class, 'filter'])->name('feed.filter');
});
require __DIR__.'/admin.php';

# Login route alias
Route::get("/login", function() { return redirect()->route("admin.login"); })->name("login");
