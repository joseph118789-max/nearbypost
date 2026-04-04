<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NewsItem extends Model
{
    protected $table = 'news_items';

    protected $fillable = [
        'title',
        'summary',
        'source',
        'url',
        'status',
        'published_at',
        'primary_category',
        'secondary_category',
        'main_place_text',
        // Alias enrichment
        'canonical_place_name',
        'alias_match_status',
        'alias_match_type',
        'alias_matched_at',
        // Geocoding
        'latitude',
        'longitude',
        'geocode_status',
        'geocode_provider',
        'geocode_confidence',
        'geocoded_at',
        // Precision & coverage
        'precision_type',
        'coverage_type',
        'geo_confidence_score',
        'coverage_status',
        'geo_processed_at',
    ];

    protected $casts = [
        'published_at'   => 'datetime',
        'latitude'       => 'float',
        'longitude'      => 'float',
        'alias_matched_at' => 'datetime',
        'geocoded_at'    => 'datetime',
        'geo_processed_at' => 'datetime',
        'geo_confidence_score' => 'float',
        'geocode_confidence' => 'float',
    ];

    public function extractionJob(): HasOne
    {
        return $this->hasOne(ExtractionJob::class);
    }

    public function aiProcessingJob()
    {
        return $this->hasOne(AiProcessingJob::class);
    }
}
