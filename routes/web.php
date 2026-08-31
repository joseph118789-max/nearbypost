<?php

use App\Http\Controllers\Auth\ContributorAuthController;
use App\Http\Controllers\ContributeController;
use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\SetLocale;
use App\Support\Loc;

/*
|--------------------------------------------------------------------------
| Public pages, one set per reading language
|--------------------------------------------------------------------------
|
| Rendered on the server. Places and topics are real URLs rather than
| JavaScript state, so each one can be linked, shared and indexed.
|
| English keeps the unprefixed URLs - those are the ones already indexed and
| there is no reason to churn them. Malay and Chinese are the same routes again
| under /ms and /zh, with the locale as a route-name prefix so
| App\Support\Loc::route() can resolve either. Each language is its own URL
| because a search engine has to index the Malay page separately from the
| English one, and a reader has to be able to share the version they read.
|
*/

$publicRoutes = require __DIR__ . '/public_pages.php';

Route::middleware(SetLocale::class . ':' . Loc::DEFAULT)->group(function () use ($publicRoutes) {
    $publicRoutes(Loc::DEFAULT);
});

foreach (Loc::all() as $locale) {
    if ($locale === Loc::DEFAULT) {
        continue;
    }

    Route::prefix($locale)
        ->name($locale . '.')
        ->middleware(SetLocale::class . ':' . $locale)
        ->group(function () use ($publicRoutes, $locale) {
            $publicRoutes($locale);
        });
}

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

/*
|--------------------------------------------------------------------------
| Signing in, and writing for the site
|--------------------------------------------------------------------------
|
| Two kinds of account behind one door: administrators run the site, and
| contributors write for it. Neither can reach the other's pages.
|
| These are not locale-prefixed. The reading languages exist so a story can be
| read three ways; a submission form is a tool, not an article.
|
*/

Route::get('/login', [ContributorAuthController::class, 'choose'])->name('login');

Route::middleware('guest:web')->group(function () {
    Route::get('/contributor/login', [ContributorAuthController::class, 'showLogin'])->name('contributor.login');
    Route::post('/contributor/login', [ContributorAuthController::class, 'login'])
        ->middleware('throttle:20,1');

    Route::get('/contributor/register', [ContributorAuthController::class, 'showRegister'])->name('contributor.register');
    Route::post('/contributor/register', [ContributorAuthController::class, 'register'])
        ->middleware('throttle:10,60');
});

Route::middleware('auth:web')->group(function () {
    Route::post('/contributor/logout', [ContributorAuthController::class, 'logout'])->name('contributor.logout');

    Route::get('/contribute', [ContributeController::class, 'index'])->name('contribute.index');
    Route::get('/contribute/new', [ContributeController::class, 'create'])->name('contribute.create');
    Route::post('/contribute', [ContributeController::class, 'store'])->name('contribute.store');
    Route::get('/contribute/{id}/edit', [ContributeController::class, 'edit'])
        ->whereNumber('id')->name('contribute.edit');
    Route::put('/contribute/{id}', [ContributeController::class, 'update'])
        ->whereNumber('id')->name('contribute.update');
    Route::delete('/contribute/{id}', [ContributeController::class, 'destroy'])
        ->whereNumber('id')->name('contribute.destroy');
});

// A contributed story's own page. Gathered articles link out to their
// publisher; these were written here, so here is where they live.
Route::get('/post/{id}', [PostController::class, 'show'])->whereNumber('id')->name('post.show');
