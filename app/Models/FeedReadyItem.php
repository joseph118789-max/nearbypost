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
        // Extended serving fields
        'canonical_place_name',
        'geo_confidence_score',
        'coverage_type',
        'sort_timestamp',
    ];

    protected $casts = [
        'published_at'       => 'datetime',
        'lat'                => 'float',
        'lng'                => 'float',
        'distance_km'        => 'float',
        'geo_confidence_score'=> 'float',
        'is_active'          => 'boolean',
    ];

    public const PRECISION_NEARBY   = ['exact_area', 'approximate_area'];
    public const PRECISION_EXCLUDED = ['region', 'broad', 'unknown'];
    public const RELEVANCE_GEO       = ['location_only', 'location_and_category'];
}
