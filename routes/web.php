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

// The sidebar player's songs. Language-neutral, so it sits outside the
// per-language page group: the catalogue is the same whichever language the
// news is being read in.
Route::get('/music/tracks', [App\Http\Controllers\MusicController::class, 'tracks'])->name('music.tracks');

Route::get('/sitemap.xml', [SitemapController::class, 'index']);
Route::get('/sitemap-core.xml', [SitemapController::class, 'core']);
Route::get('/sitemap-stories.xml', [SitemapController::class, 'stories']);
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

    Route::get('/contributor/username-check', [ContributorAuthController::class, 'checkUsername'])->name('contributor.username.check');
    Route::get('/contributor/register', [ContributorAuthController::class, 'showRegister'])->name('contributor.register');
    Route::post('/contributor/register', [ContributorAuthController::class, 'register'])
        ->middleware('throttle:10,60');
});

Route::middleware('auth:web')->group(function () {
    Route::post('/contributor/logout', [ContributorAuthController::class, 'logout'])->name('contributor.logout');
    // the Marketplace choice on the camera sheet: a sign-in, then the news that listings are not open yet
    Route::get('/marketplace/offer', fn () => view('pages.marketplace-offer', ['pageTitle' => __('site.post_market_title')]))->name('marketplace.offer');

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

// Community Reports: reactions and reports (anonymous allowed, low weight), the @username profiles, follows
Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/community/reverse', [App\Http\Controllers\CommunityController::class, 'reverse'])->name('community.reverse');
    Route::get('/community/{id}/panel', [App\Http\Controllers\CommunityController::class, 'panel'])->whereNumber('id')->name('community.panel');
    // reading a comment in your own language needs no account; one AI call per comment and language, then cached
    Route::get('/community/comments/{comment}/translation', [App\Http\Controllers\CommunitySocialController::class, 'translateComment'])->whereNumber('comment')->name('community.comment.translate');
    Route::post('/community/{id}/reactions', [App\Http\Controllers\CommunityController::class, 'react'])->whereNumber('id')->name('community.react');
    Route::post('/community/{id}/reports', [App\Http\Controllers\CommunityController::class, 'report'])->whereNumber('id')->name('community.report');
    Route::get('/people', [App\Http\Controllers\CommunityController::class, 'people'])->name('community.people');
    Route::get('/@{username}', [App\Http\Controllers\CommunityController::class, 'profile'])->where('username', '[A-Za-z0-9_.]{3,30}')->name('community.profile');
});
Route::middleware('auth:web')->group(function () {
    $S = App\Http\Controllers\CommunitySocialController::class;
    Route::post('/community/{id}/comments', [$S, 'comment'])->whereNumber('id')->name('community.comment');
    Route::patch('/community/comments/{comment}', [$S, 'editComment'])->whereNumber('comment')->name('community.comment.edit');
    Route::delete('/community/comments/{comment}', [$S, 'deleteComment'])->whereNumber('comment')->name('community.comment.delete');
    Route::post('/community/comments/{comment}/reports', [$S, 'reportComment'])->whereNumber('comment')->name('community.comment.report');
    Route::post('/community/{id}/corrections', [$S, 'proposeCorrection'])->whereNumber('id')->name('community.correction');
    Route::post('/community/corrections/{correction}/{decision}', [$S, 'resolveCorrection'])->whereNumber('correction')->where('decision', 'accept|reject')->name('community.correction.resolve');
    Route::post('/community/{id}/appeals', [$S, 'appeal'])->whereNumber('id')->name('community.appeal');
    Route::get('/community/follows', [$S, 'follows'])->name('community.follows');
    Route::post('/community/follows/areas', [$S, 'followArea'])->name('community.follows.area');
    Route::post('/community/follows/areas/{follow}/{action}', [$S, 'areaAction'])->whereNumber('follow')->where('action', 'pause|resume|delete')->name('community.follows.area.action');
    Route::post('/community/follows/topics', [$S, 'followTopic'])->name('community.follows.topic');
    Route::post('/community/follows/topics/{follow}/{action}', [$S, 'topicAction'])->whereNumber('follow')->where('action', 'pause|resume|delete')->name('community.follows.topic.action');
    Route::post('/community/follows/mode', [$S, 'notifyMode'])->name('community.follows.mode');
    Route::get('/community/notifications', [$S, 'notifications'])->name('community.notifications');
    Route::get('/community/moderate', [$S, 'moderate'])->name('community.moderate');
    Route::post('/community/moderate/{id}', [$S, 'moderateAct'])->whereNumber('id')->name('community.moderate.act');
    Route::post('/@{username}/block', [$S, 'block'])->where('username', '[A-Za-z0-9_.]{3,30}')->name('community.block');
    Route::post('/@{username}/follow', [App\Http\Controllers\CommunityController::class, 'follow'])->where('username', '[A-Za-z0-9_.]{3,30}')->name('community.follow');
    Route::delete('/@{username}/follow', [App\Http\Controllers\CommunityController::class, 'unfollow'])->where('username', '[A-Za-z0-9_.]{3,30}')->name('community.unfollow');
});
Route::get('/sitemap-news.xml', [SitemapController::class, 'news']);
Route::get('/community/leaderboards/{area?}', [App\Http\Controllers\CommunitySocialController::class, 'leaderboard'])->name('community.leaderboard');
