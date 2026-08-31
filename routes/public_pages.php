<?php

use App\Http\Controllers\HomeController;
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
    Route::get('/marketplace', [HomeController::class, 'marketplace'])->name('marketplace');

    Route::get('/category/{slug}', [HomeController::class, 'category'])
        ->where('slug', '[a-z0-9-]+')->name('category');

    Route::get('/news/{slug}', [HomeController::class, 'place'])
        ->where('slug', '[a-z0-9-]+')->name('place');

    Route::get('/news/{slug}/{categorySlug}', [HomeController::class, 'placeCategory'])
        ->where(['slug' => '[a-z0-9-]+', 'categorySlug' => '[a-z0-9-]+'])->name('place.category');

    Route::get('/legal/{page}', [HomeController::class, 'legal'])
        ->where('page', 'terms|privacy|disclaimer')->name('legal');
};
