<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Rebuild and cache the home feed page.
 * Idempotent: simply overwrites the cache key with fresh data.
 */
class RefreshHomeFeedCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $items = \App\Models\FeedReadyItem::where('is_active', true)
            ->orderBy('published_at', 'desc')
            ->limit(20)
            ->get(['id','title','summary','source','published_at','primary_category','secondary_category','url'])
            ->toArray();

        Cache::put('feed:home:default', $items, 300);
    }
}