<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicEntity extends Model
{
    protected $fillable = [
        'news_item_id',
        'type',
        'name',
        'normalized_name',
        'confidence',
        'topic_cluster',
        'topic_score',
        'first_position',
        'last_position',
        'occurrence_count',
        'extraction_model',
        'extracted_at',
    ];

    protected $casts = [
        'first_position' => 'integer',
        'last_position' => 'integer',
        'occurrence_count' => 'integer',
        'topic_score' => 'integer',
        'extracted_at' => 'datetime',
    ];

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }

    // Entity types
    const TYPE_PERSON = 'person';
    const TYPE_BRAND = 'brand';
    const TYPE_ORGANIZATION = 'organization';
    const TYPE_EVENT = 'event';
    const TYPE_LOCATION = 'location';
    const TYPE_TOPIC = 'topic';

    // Confidence levels
    const CONFIDENCE_HIGH = 'high';
    const CONFIDENCE_MEDIUM = 'medium';
    const CONFIDENCE_LOW = 'low';

    /**
     * Scope by entity type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope by topic cluster
     */
    public function scopeInCluster($query, string $cluster)
    {
        return $query->where('topic_cluster', $cluster);
    }

    /**
     * Get unique topic clusters from all entities
     */
    public static function getClusters(): array
    {
        return static::whereNotNull('topic_cluster')
            ->distinct()
            ->pluck('topic_cluster')
            ->toArray();
    }
}