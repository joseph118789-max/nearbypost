<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Rebuild and cache a single category feed page.
 * Idempotent: simply overwrites the cache key with fresh data.
 */
class RefreshCategoryFeedCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        private readonly string $category
    ) {}

    public function handle(): void
    {
        $items = \App\Models\FeedReadyItem::where('is_active', true)
            ->where('primary_category', $this->category)
            ->orderBy('published_at', 'desc')
            ->limit(20)
            ->get(['id','title','summary','source','published_at','primary_category','secondary_category','url'])
            ->toArray();

        Cache::put('feed:category:' . $this->category, $items, 300);
    }
}