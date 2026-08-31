<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SitemapController;

/*
|--------------------------------------------------------------------------
| Public pages
|--------------------------------------------------------------------------
|
| Rendered on the server. Places and topics are real URLs rather than
| JavaScript state, so each one can be linked, shared and indexed.
|
*/

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/interest', [HomeController::class, 'interest'])->name('interest');
Route::get('/marketplace', [HomeController::class, 'marketplace'])->name('marketplace');

Route::get('/category/{slug}', [HomeController::class, 'category'])
    ->where('slug', '[a-z0-9-]+')
    ->name('category');

Route::get('/news/{slug}', [HomeController::class, 'place'])
    ->where('slug', '[a-z0-9-]+')
    ->name('place');

Route::get('/news/{slug}/{categorySlug}', [HomeController::class, 'placeCategory'])
    ->where(['slug' => '[a-z0-9-]+', 'categorySlug' => '[a-z0-9-]+'])
    ->name('place.category');

Route::get('/legal/{page}', [HomeController::class, 'legal'])
    ->where('page', 'terms|privacy|disclaimer')
    ->name('legal');

/*
|--------------------------------------------------------------------------
| Crawler and answer-engine endpoints
|--------------------------------------------------------------------------
*/

Route::get('/sitemap.xml', [SitemapController::class, 'index']);
Route::get('/sitemap-core.xml', [SitemapController::class, 'core']);
Route::get('/sitemap-places.xml', [SitemapController::class, 'places']);
Route::get('/sitemap-topics.xml', [SitemapController::class, 'topics']);
Route::get('/robots.txt', [SitemapController::class, 'robots']);
Route::get('/llms.txt', [SitemapController::class, 'llms']);

/*
|--------------------------------------------------------------------------
| Legacy
|--------------------------------------------------------------------------
|
| /by_category pointed at a view that was never created and returned a 500.
| Categories now have their own pages, so it redirects rather than erroring.
|
*/

Route::permanentRedirect('/by_category', '/interest');

/*
|--------------------------------------------------------------------------
| JSON feed, kept for the mobile clients
|--------------------------------------------------------------------------
*/

Route::prefix('feed')->middleware(['throttle:60,1'])->group(function () {
    Route::get('/default', [FeedController::class, 'default'])->name('feed.default');
    Route::get('/category/{slug}', [FeedController::class, 'byCategory'])->name('feed.category');
    Route::get('/nearby', [FeedController::class, 'nearby'])->name('feed.nearby');
    Route::get('/filter', [FeedController::class, 'filter'])->name('feed.filter');
});

require __DIR__ . '/admin.php';

Route::get('/login', fn () => redirect()->route('admin.login'))->name('login');
