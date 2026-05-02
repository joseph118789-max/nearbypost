<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Rebuild and cache the categories list.
 * Idempotent: simply overwrites the cache key with fresh data.
 */
class RefreshCategoriesListCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $cats = \App\Models\FeedReadyItem::where('is_active', true)
            ->select('primary_category')
            ->distinct()
            ->whereNotNull('primary_category')
            ->orderBy('primary_category')
            ->pluck('primary_category')
            ->values()
            ->toArray();

        Cache::put('feed:categories:list', $cats, 300);
    }
}