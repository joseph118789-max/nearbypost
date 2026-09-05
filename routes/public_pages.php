<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\StoryController;
use Illuminate\Support\Facades\Route;

/**
 * The public pages, registered once per reading language.
 *
 * English keeps the unprefixed URLs, which are the ones already indexed.
 * Malay and Chinese are registered again under /ms and /zh with the locale as a
 * route-name prefix, so App\Support\Loc::route() can resolve either.
 */
return function (string $locale): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/interest', [HomeController::class, 'interest'])->name('interest');
    // "Send us your news" - the invitation, and the form behind it.
    Route::get('/share-your-news', [HomeController::class, 'share'])->name('share');
    Route::post('/share-your-news', [HomeController::class, 'shareSubmit'])
        ->middleware('throttle:10,60')->name('share.submit');
    Route::get('/marketplace', [HomeController::class, 'marketplace'])->name('marketplace');
    // One section. Constrained to the codes that exist, so /marketplace/offer
    // keeps its own route and a typo 404s instead of rendering an empty page.
    Route::get('/marketplace/{section}', [HomeController::class, 'marketplaceSection'])
        ->whereIn('section', ['property','food_dining','home_renovation','buy_sell','professional_services','car_pool'])
        ->name('marketplace.section');

    // The browser hands over a point; this turns it into the same kind of place
    // name a reader would have typed and redirects there, so the address bar
    // ends up somewhere shareable and a refresh does not ask for a position
    // again. Registered per language like everything else here.
    Route::get('/locate', [HomeController::class, 'locate'])->name('locate');

    Route::get('/category/{slug}', [HomeController::class, 'category'])
        ->where('slug', '[a-z0-9-]+')->name('category');

    Route::get('/news/{slug}', [HomeController::class, 'place'])
        ->where('slug', '[a-z0-9-]+')->name('place');

    Route::get('/news/{slug}/{categorySlug}', [HomeController::class, 'placeCategory'])
        ->where(['slug' => '[a-z0-9-]+', 'categorySlug' => '[a-z0-9-]+'])->name('place.category');

    // Where a forwarded headline lands. Registered per reading language like
    // the rest, so a story shared by a Malay reader opens in Malay.
    Route::get('/story/{id}', [StoryController::class, 'show'])
        ->whereNumber('id')->name('story');

    Route::get('/legal/{page}', [HomeController::class, 'legal'])
        ->where('page', 'terms|privacy|disclaimer')->name('legal');
};
