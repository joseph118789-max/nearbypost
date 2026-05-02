<?php

namespace App\Models;

use App\Observers\NewsItemObserver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NewsItem extends Model
{
    use HasFactory;

    protected $fillable = [
        "title",
        "url",
        "source",
        "published_at",
        "summary",
        "primary_category",
        "secondary_category",
        "extracted_title",
        "extracted_summary",
        "extracted_text",
        "extracted_author",
        "extracted_image_url",
        "ai_summary",
        "ai_category",
        "main_place_text",
        "relevance_mode",
        "is_article",
        "ai_status",
        "ai_processed_at",
        "ai_model",
        "ai_prompt_version",
        "ai_tokens_in",
        "ai_tokens_out",
        "ai_estimated_cost",
        "latitude",
        "longitude",
        "precision_type",
        "geocode_confidence",
        "geo_confidence_score",
        "status",
        "is_active",
    ];

    protected $casts = [
        "published_at" => "datetime",
        "ai_processed_at" => "datetime",
        "ai_tokens_in" => "integer",
        "ai_tokens_out" => "integer",
        "ai_estimated_cost" => "float",
        "latitude" => "float",
        "longitude" => "float",
        "geocode_confidence" => "float",
        "geo_confidence_score" => "float",
        "is_active" => "boolean",
        "is_article" => "boolean",
    ];

    protected static function booted(): void
    {
        static::observe(NewsItemObserver::class);
    }

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

    public function extractionJob(): HasOne
    {
        return $this->hasOne(ExtractionJob::class);
    }

    public function aiProcessingJob(): HasOne
    {
        return $this->hasOne(AiProcessingJob::class)->latestOfMany();
    }
}
