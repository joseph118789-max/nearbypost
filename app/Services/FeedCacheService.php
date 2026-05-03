<?php

namespace App\Services;

use App\Jobs\RefreshHomeFeedCache;
use App\Jobs\RefreshCategoryFeedCache;
use App\Jobs\RefreshCategoriesListCache;
use Illuminate\Support\Facades\Cache;

/**
 * Centralized feed cache invalidation for D17.
 *
 * Call these methods after any FeedReadyItem is created, updated, or deleted.
 * Invalidation is immediate; refresh jobs rebuild cache asynchronously.
 */
class FeedCacheService
{
    // ── Keys ────────────────────────────────────────────────────────────────
    public const KEY_HOME     = 'feed:home:default';
    public const KEY_CATEGORIES = 'feed:categories:list';
    public const KEY_CATEGORY_PREFIX = 'feed:category:';

    /**
     * Invalidate all caches affected by a category-scoped item.
     * Used for create, update, and delete of FeedReadyItem.
     */
    public function invalidateForCategory(?string $category, ?string $oldCategory = null): void
    {
        // Always invalidate home and categories list
        $this->forgetHome();
        $this->forgetCategories();

        // Invalidate current category if set
        if ($category) {
            $this->forgetCategory($category);
        }

        // Also invalidate old category if it changed
        if ($oldCategory && $oldCategory !== $category) {
            $this->forgetCategory($oldCategory);
        }

        // Dispatch refresh jobs asynchronously
        RefreshHomeFeedCache::dispatch();
        RefreshCategoriesListCache::dispatch();
        if ($category) {
            RefreshCategoryFeedCache::dispatch($category);
        }
        if ($oldCategory && $oldCategory !== $category) {
            RefreshCategoryFeedCache::dispatch($oldCategory);
        }
    }

    /**
     * Invalidate just home + categories list (e.g., bulk operations).
     */
    public function invalidateHomeAndCategories(): void
    {
        $this->forgetHome();
        $this->forgetCategories();
        RefreshHomeFeedCache::dispatch();
        RefreshCategoriesListCache::dispatch();
    }

    // ── Key-level helpers (for manual use) ────────────────────────────────
    public function forgetHome(): void
    {
        Cache::forget(self::KEY_HOME);
    }

    public function forgetCategories(): void
    {
        Cache::forget(self::KEY_CATEGORIES);
    }

    public function forgetCategory(string $slug): void
    {
        Cache::forget(self::KEY_CATEGORY_PREFIX . $slug);
    }
}