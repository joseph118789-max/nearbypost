<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\NewsItem;
use App\Observers\NewsItemObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        NewsItem::observe(NewsItemObserver::class);
    }
}
