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
        'relevance_score',
        'distance_km',
        'calculated_at',
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
    ];

    protected $casts = [
        'news_item_id' => 'integer',
        'relevance_score' => 'float',
        'distance_km' => 'float',
        'calculated_at' => 'datetime',
        'published_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
        'is_active' => 'boolean',
    ];

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}