<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiProcessingJob extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'news_item_id',
        'ai_summary',
        'ai_category',
        'main_place_text',
        'relevance_mode',
        'ai_status',
        'model_used',
        'prompt_version',
        'pipeline_version',
        'tokens_in',
        'tokens_out',

        // ⛔ THE $fillable TRAP, FOR THE THIRD TIME ON THIS PROJECT.
        //
        // These two columns exist, EnrichWithAi passes them on every call, and
        // DeepSeek returns them in its usage block - but they were not listed
        // here, so Eloquent dropped them silently on every write. Not one row
        // in the table had a cache split, which meant estimateCost() could
        // never take the cache-aware branch and fell through to a generic
        // rate. Cached input is a quarter the price of fresh, and most input
        // here IS cached by design, so the estimate ran far above the bill.
        //
        // Nothing errors when a field is missing from $fillable. It is simply
        // not written. Add the column, add it HERE, and check a real row.
        'cache_hit_tokens',
        'cache_miss_tokens',

        'estimated_cost',
        'error_message',
        'raw_ai_output',
        'validated_summary',
        'validated_category',
        'validated_place',
        'validation_notes',
        'processed_at',
        'is_article',
    ];

    protected $casts = [
        'tokens_in'         => 'integer',
        'tokens_out'        => 'integer',
        'cache_hit_tokens'  => 'integer',
        'cache_miss_tokens' => 'integer',
        'estimated_cost' => 'decimal:6',
        'is_article' => 'boolean',
    ];

    // ── Controlled enums ────────────────────────────────────────────────────
    public const RELEVANCE_MODES = ['category_only', 'location_only', 'location_and_category'];
    public const AI_STATUSES     = ['pending', 'processing', 'success', 'failed', 'invalid_output', 'fallback_used'];
    public const VALID_CATEGORIES = [
        'technology','politics','business','sports','entertainment',
        'health','science','world','local','other',
    ];

    public function newsItem()
    {
        return $this->belongsTo(NewsItem::class);
    }
}
