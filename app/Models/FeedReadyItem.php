<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedReadyItem extends Model
{
    protected $table = 'feed_ready_items';

    protected $fillable = [
        'news_item_id',
        'title',
        'summary',
        'source',
        'url',
        'published_at',
        'primary_category',
        'secondary_category',
        'location_label',
        'lat',
        'lng',
        'precision_type',
        'distance_km',
        'relevance_mode',
        'is_active',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
        'distance_km' => 'float',
        'is_active' => 'boolean',
    ];

    public const PRECISION_ALLOWED = ['exact_area','approximate_area'];
    public const PRECISION_EXCLUDED = ['state_center','region','country','national','unresolved'];
    public const RELEVANCE_NEARBY = ['location_only','hybrid'];
}
