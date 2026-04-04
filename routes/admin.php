<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IntelController;
use App\Http\Controllers\Admin\NewsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SubscriberController;
use Illuminate\Support\Facades\Route;

Route::prefix("admin")->name("admin.")->group(function () {

    // Guest routes
    Route::middleware("guest:admin")->group(function () {
        Route::get("/login", [App\Http\Controllers\Admin\Auth\LoginController::class, "showLoginForm"])->name("login");
        Route::post("/login", [App\Http\Controllers\Admin\Auth\LoginController::class, "login"]);
    });

    // Protected routes
    Route::middleware("auth:admin")->group(function () {
        Route::get("/", [DashboardController::class, "index"])->name("index");
        Route::get("/dashboard", [DashboardController::class, "index"])->name("dashboard");
        Route::post("/logout", [App\Http\Controllers\Admin\Auth\LoginController::class, "logout"])->name("logout");

        Route::get("/news", [NewsController::class, "index"])->name("news.index");
        Route::post("/news", [NewsController::class, "store"])->name("news.store");
        Route::get("/news/{id}", [NewsController::class, "show"])->name("news.show");
        Route::put("/news/{id}", [NewsController::class, "update"])->name("news.update");
        Route::delete("/news/{id}", [NewsController::class, "destroy"])->name("news.destroy");

        Route::get("/subscribers", [SubscriberController::class, "index"])->name("subscribers.index");
        Route::post("/subscribers", [SubscriberController::class, "store"])->name("subscribers.store");
        Route::get("/subscribers/{id}", [SubscriberController::class, "show"])->name("subscribers.show");
        Route::put("/subscribers/{id}", [SubscriberController::class, "update"])->name("subscribers.update");
        Route::delete("/subscribers/{id}", [SubscriberController::class, "destroy"])->name("subscribers.destroy");

        Route::get("/settings/categories", [SettingsController::class, "getCategories"])->name("settings.categories");
        Route::post("/settings/categories", [SettingsController::class, "saveCategories"])->name("settings.categories.save");
        Route::get("/settings/wa-groups", [SettingsController::class, "getWAGroups"])->name("settings.wa-groups");
        Route::post("/settings/wa-groups", [SettingsController::class, "saveWAGroups"])->name("settings.wa-groups.save");

        Route::get("/intel/analytics", [IntelController::class, "analytics"])->name("intel.analytics");
    });
});
