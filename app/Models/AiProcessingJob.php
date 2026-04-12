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
        'tokens_in'  => 'integer',
        'tokens_out' => 'integer',
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
