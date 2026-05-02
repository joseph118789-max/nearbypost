<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedReadyItem extends Model
{
    use HasFactory;

    protected $table = 'feed_ready_items';

    protected $fillable = [
        'news_item_id',
        'title',
        'summary',
        'source',
        'published_at',
        'primary_category',
        'secondary_category',
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
