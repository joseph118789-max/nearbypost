<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class NewsItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'url',
        'source',
        'published_at',
        'summary',
        'primary_category',
        'secondary_category',
        // Extracted fields
        'extracted_title',
        'extracted_summary',
        'extracted_text',
        'extracted_author',
        'extracted_image_url',
        // AI enrichment fields
        'ai_summary',
        'ai_category',
        'main_place_text',
        'relevance_mode',
        'ai_status',
        'ai_processed_at',
        'ai_model',
        'ai_prompt_version',
        'ai_tokens_in',
        'ai_tokens_out',
        'ai_estimated_cost',
        // Geo fields
        'latitude',
        'longitude',
        'precision_type',
        'geo_confidence',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'ai_processed_at' => 'datetime',
        'ai_tokens_in' => 'integer',
        'ai_tokens_out' => 'integer',
        'ai_estimated_cost' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function feedReadyItem(): HasOne
    {
        return $this->hasOne(FeedReadyItem::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function topicEntities(): HasMany
    {
        return $this->hasMany(TopicEntity::class);
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    public function scopeAiProcessed($query)
    {
        return $query->where('ai_status', 'success');
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('published_at', '>=', now()->subHours($hours));
    }
}