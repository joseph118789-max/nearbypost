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
        // One paginator everywhere. The framework default draws inline SVG
        // arrows sized by Tailwind, which no page here loads.
        \Illuminate\Pagination\Paginator::defaultView("vendor.pagination.nearbypost");
        \Illuminate\Pagination\Paginator::defaultSimpleView("vendor.pagination.nearbypost");

        NewsItem::observe(NewsItemObserver::class);
    }
}
