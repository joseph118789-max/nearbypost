<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtractionJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'news_item_id',
        'extracted_title',
        'extracted_summary',
        'extracted_text',
        'extraction_status',
        'extraction_method',
        'extracted_at',
    ];

    public function newsItem(): BelongsTo
    {
        return $this->belongsTo(NewsItem::class);
    }
}
