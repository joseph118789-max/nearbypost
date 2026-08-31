<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedReadyItem extends Model
{
    use HasFactory;

    protected $table = 'feed_ready_items';

    // ── D17: Feed cache invalidation on serving-layer changes ───────────────
    protected static function booted(): void
    {
        // On create: invalidate home + categories + that category's feed
        static::created(function (FeedReadyItem $item) {
            app(\App\Services\FeedCacheService::class)
                ->invalidateForCategory($item->primary_category);
        });

        // On update: invalidate home + categories + both old and new category
        static::updated(function (FeedReadyItem $item) {
            $oldCategory = $item->getOriginal('primary_category');
            $newCategory = $item->primary_category;
            app(\App\Services\FeedCacheService::class)
                ->invalidateForCategory($newCategory, $oldCategory);
        });

        // On delete: same as update (invalidate home, categories, that category)
        static::deleted(function (FeedReadyItem $item) {
            app(\App\Services\FeedCacheService::class)
                ->invalidateForCategory($item->primary_category);
        });
    }

    protected $fillable = [
        'news_item_id',
        'title',
        'summary',
        'source',
        'published_at',
        'primary_category',
        'secondary_category',
        'sub_category',
        'url',
        'location_label',
        'lat',
        'lng',
        'is_active',
        'relevance_mode',
        'precision_type',
        'canonical_place_name',
        'geo_confidence_score',
        'coverage_type',
        'sort_timestamp',
        'is_article',
    ];

    protected $casts = [
        'news_item_id' => 'integer',
        'published_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
        'geo_confidence_score' => 'float',
        'is_active' => 'boolean',
        'is_article' => 'boolean',
    ];

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
