<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RelevanceScore Model
 * 
 * Stores computed relevance scores for each user × news item combination.
 * Score is calculated based on:
 * - Distance decay (closer = higher)
 * - Category affinity (user's preferred categories)
 * - Freshness decay (newer = higher)
 * - Authority signal (known sources)
 */
class RelevanceScore extends Model
{
    use HasFactory;

    protected $table = 'relevance_scores';

    protected $fillable = [
        'user_id',
        'news_item_id',
        'score',
        'score_breakdown',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'news_item_id' => 'integer',
        'score' => 'decimal:4',
        'score_breakdown' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Constants for scoring weights (can be moved to config)
     */
    public const WEIGHT_DISTANCE = 0.30;
    public const WEIGHT_CATEGORY = 0.30;
    public const WEIGHT_FRESHNESS = 0.25;
    public const WEIGHT_AUTHORITY = 0.15;

    /**
     * Known authoritative sources
     */
    public const AUTHORITATIVE_SOURCES = [
        'bbc_news',
        'the_star',
        'malay_mail',
        'cna',
        'reuters',
        'ap_news',
        'afp',
    ];

    /**
     * Calculate distance decay score
     * Uses exponential decay: score = e^(-distance / decay_factor)
     * 
     * @param float $distance Distance in kilometers
     * @param float $decayFactor Decay factor (default 10km)
     * @return float Score between 0 and 1
     */
    public static function calculateDistanceScore(float $distance, float $decayFactor = 10.0): float
    {
        if ($distance <= 0) {
            return 1.0; // Maximum score for same location
        }
        
        return exp(-$distance / $decayFactor);
    }

    /**
     * Calculate category affinity score
     * 
     * @param string $itemCategory Category of the news item
     * @param array $preferredCategories User's preferred categories
     * @return float Score between 0 and 1
     */
    public static function calculateCategoryScore(string $itemCategory, array $preferredCategories = []): float
    {
        if (empty($preferredCategories)) {
            return 0.5; // Default middle score if no preferences
        }

        // Exact match gets highest score
        if (in_array(strtolower($itemCategory), array_map('strtolower', $preferredCategories))) {
            return 1.0;
        }

        // Partial match gets partial score
        foreach ($preferredCategories as $preferred) {
            if (stripos($itemCategory, $preferred) !== false || stripos($preferred, $itemCategory) !== false) {
                return 0.7;
            }
        }

        return 0.2; // Low score for non-matching categories
    }

    /**
     * Calculate freshness decay score
     * Uses exponential decay based on article age
     * 
     * @param \Carbon\Carbon|null $publishedAt Publication date
     * @param float $decayFactor Hours until score drops to ~37% (default 24 hours)
     * @return float Score between 0 and 1
     */
    public static function calculateFreshnessScore(?\Carbon\Carbon $publishedAt, float $decayFactor = 24.0): float
    {
        if (!$publishedAt) {
            return 0.5; // Default if no date
        }

        $hoursOld = now()->diffInHours($publishedAt);
        
        if ($hoursOld < 0) {
            return 1.0; // Future article, max score
        }

        return exp(-$hoursOld / $decayFactor);
    }

    /**
     * Calculate authority score based on source
     * 
     * @param string $source News source identifier
     * @return float Score between 0 and 1
     */
    public static function calculateAuthorityScore(string $source): float
    {
        $source = strtolower($source);

        if (in_array($source, self::AUTHORITATIVE_SOURCES)) {
            return 1.0;
        }

        // Partial matches for known sources
        foreach (self::AUTHORITATIVE_SOURCES as $authority) {
            if (strpos($source, $authority) !== false || strpos($authority, $source) !== false) {
                return 0.8;
            }
        }

        return 0.3; // Default for unknown sources
    }

    /**
     * Calculate total relevance score from breakdown components
     * 
     * @param array $breakdown Score components with keys: distance, category, freshness, authority
     * @return float Weighted total score
     */
    public static function calculateTotalScore(array $breakdown): float
    {
        $distance = $breakdown['distance'] ?? 0.5;
        $category = $breakdown['category'] ?? 0.5;
        $freshness = $breakdown['freshness'] ?? 0.5;
        $authority = $breakdown['authority'] ?? 0.3;

        return (
            ($distance * self::WEIGHT_DISTANCE) +
            ($category * self::WEIGHT_CATEGORY) +
            ($freshness * self::WEIGHT_FRESHNESS) +
            ($authority * self::WEIGHT_AUTHORITY)
        );
    }

    /**
     * User relationship
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'user_id');
    }

    /**
     * News item relationship (if news_items table exists)
     */
    public function newsItem(): BelongsTo
    {
        // Assuming news_items table exists - adjust model name as needed
        return $this->belongsTo(NewsItem::class, 'news_item_id');
    }

    /**
     * Scope to get top scores for a user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId)->orderByDesc('score');
    }

    /**
     * Scope to get scores above a threshold
     */
    public function scopeAboveThreshold($query, float $threshold = 0.5)
    {
        return $query->where('score', '>=', $threshold);
    }
}